<?php

namespace App\System\Services;

use App\Models\Setting;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

/**
 * Host ModSecurity rulesets under the Engine install config.
 *
 * Driver-level toggleModsecurity (vhost rewrite) is not on App\System\Webserver
 * yet; rebuildConfig writes main.conf then reloads/restarts the sites-http container.
 */
class Modsec
{
    /** A rule file name as toggleConfigFiles() accepts it: as listed, with or without `.disabled`. */
    public const CONFIG_FILE_NAME = '/\A[A-Za-z0-9_][A-Za-z0-9._-]*\.conf(?:\.disabled)?\z/';

    /** The rule set the operator writes through the API; enabled and included like the shipped ones. */
    public const CUSTOM_RULESET = 'custom';
    public const CUSTOM_RULES_FILE = 'custom.conf';

    /**
     * Rule ids a custom rule may take: clear of OWASP CRS (900000-999999) and
     * of the engine's own rules (1000000-1009999).
     */
    public const CUSTOM_ID_MIN = 1100000;
    public const CUSTOM_ID_MAX = 1199999;

    public const CUSTOM_RULES_MAX_BYTES = 262144;

    /** Rules only: no Include, no log or data paths, no remote rule sources. */
    private const CUSTOM_DIRECTIVES = [
        'secrule',
        'secaction',
        'secmarker',
        'secruleremovebyid',
        'secruleremovebytag',
        'secruleremovebymsg',
        'secruleupdateactionbyid',
        'secruleupdatetargetbyid',
        'secruleupdatetargetbytag',
        'secruleupdatetargetbymsg',
    ];

    /** How long the webserver's config test may take; CRS alone is a few thousand rules to parse. */
    private const CONFIG_TEST_TIMEOUT = 60;

    public function __construct(
        private EngineSystem $system,
    ) {
    }

    public function getRulesets(): array
    {
        $dir = $this->system->engineDirPath() . '/config/modsecurity/rulesets';
        $sets = glob($dir . "/*", GLOB_ONLYDIR);

        $rulesets = [];
        foreach ($sets as $set) {
            $name = basename($set);
            if (!is_dir($set . '/rules')) {
                continue;
            }
            $configFiles = glob($set . '/rules/*.conf*');
            $files = [];
            foreach ($configFiles as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if (Str::endsWith($file, [
                    '.conf',
                    '.conf.disabled',
                ])) {
                    $files[] = basename($file);
                }
            }

            $rulesets[] = [
                'name' => $name,
                'config_files' => $files,
            ];
        }
        return $rulesets;
    }

    public function rulesetExists(string $name): bool
    {
        $dir = $this->system->engineDirPath() . '/config/modsecurity/rulesets/' . $name;
        return is_dir($dir);
    }

    /**
     * @return array<array{
     *   file: string,
     *   path: string,
     *   mtime: int,
     *   size: int,
     * }>
     */
    public function listAuditLogFiles(): array
    {
        $dir = $this->system->engineDirPath() . '/logs/modsecurity';
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $fileNames = [];

        foreach (scandir($dir) as $file) {
            if (!is_file($dir . '/' . $file)) {
                continue;
            }
            $fileNames[] = $file;
        }

        foreach ($fileNames as $fileName) {
            $files[] = [
                'file' => $fileName,
                'path' => "{$dir}/{$fileName}",
                'mtime' => filemtime("{$dir}/{$fileName}"),
                'size' => filesize("{$dir}/{$fileName}"),
            ];
        }

        return $files;
    }

    public function rebuildConfig(): void
    {
        $config = Setting::getModsecConfig();

        $mainConfPath = $this->system->engineDirPath() . '/config/modsecurity/main.conf';

        $mode = 'Off';
        switch ($config['mode']) {
            case 'on':
                $mode = 'On';
                break;
            case 'detection_only':
                $mode = 'DetectionOnly';
                break;
        }

        $mainConf = $this->renderMainConf($mode);
        $this->system->filesystem()->filePutContents($mainConfPath, $mainConf);

        $this->system->webserver()->driver()->toggleModsecurity();
    }

    /**
     * main.conf over the enabled rule sets. With $customRules, that file
     * stands in for the custom rule set, enabled or not -- what main.conf
     * will be once the candidate rules are live.
     */
    private function renderMainConf(string $mode, ?string $customRules = null): string
    {
        $config = Setting::getModsecConfig();
        $mainConfTemplatePath = $this->system->engineDirPath() . '/templates/config/modsecurity-main.conf.blade.php';
        $mainConfTemplate = $this->system->filesystem()->fileGetContents($mainConfTemplatePath);

        $includeFiles = [];
        foreach ($config['enabled_rulesets'] as $ruleset) {
            if ($customRules !== null && $ruleset === self::CUSTOM_RULESET) {
                continue;
            }
            $dir = $this->system->engineDirPath() . '/config/modsecurity/rulesets/' . $ruleset;
            if (!is_dir($dir)) {
                continue;
            }
            if (is_file($dir . '/setup.conf')) {
                $includeFiles[] = $dir . '/setup.conf';
            }
            $includeFiles[] = $dir . '/rules/*.conf';
        }
        if ($customRules !== null) {
            $includeFiles[] = $customRules;
        }

        return Blade::render($mainConfTemplate, [
            'SecRuleEngine' => $mode,
            'includeFiles' => $includeFiles,
        ]);
    }

    /**
     * ModSecurity rule files are loaded when the webserver process starts.
     * Full restart is required on OLS/Apache; nginx in Docker must reload instead —
     * init.d restart exits PID 1 and loops the container.
     */
    public function restartWebserver(): void
    {
        $webserver = $this->system->webserver();
        try {
            $slug = $webserver->getCurrentWebserver();
        } catch (\Exception $e) {
            $slug = '';
        }

        if (in_array($slug, ['nginx', 'nginx-proxy'], true)) {
            $webserver->scheduleWebserverReloadInBackground();
            return;
        }

        $webserver->restartWebserverContainerFromHost();
    }

    /**
     * @param string $ruleset
     * @param array<string> $enable
     * @param array<string> $disable
     */
    public function toggleConfigFiles(string $ruleset, array $enable = [], array $disable = []): void
    {
        // Joined to a path and renamed as root: a `../` would rename any file.
        foreach ([$ruleset, ...$enable, ...$disable] as $name) {
            if ($name !== basename($name) || $name === '..' || $name === '.') {
                throw new \InvalidArgumentException("Invalid ModSecurity file name: {$name}");
            }
        }

        $dir = $this->system->engineDirPath() . '/config/modsecurity/rulesets/' . $ruleset . '/rules';
        if (!is_dir($dir)) {
            return;
        }

        // getRulesets() lists a disabled file as `X.conf.disabled`; both names mean X.conf.
        $canonical = fn (string $name): string => preg_replace('/\.disabled\z/', '', $name);
        $enable = array_map($canonical, $enable);
        $disable = array_map($canonical, $disable);

        $shouldRestart = false;
        foreach ($enable as $filename) {
            $filePath = $dir . '/' . $filename;
            if (is_file($filePath . '.disabled')) {
                $this->runProcessOrFail(['sudo', 'mv', $filePath . '.disabled', $filePath]);
                $shouldRestart = true;
            }
        }

        foreach ($disable as $filename) {
            $filePath = $dir . '/' . $filename;
            if (is_file($filePath)) {
                $this->runProcessOrFail(['sudo', 'mv', $filePath, $filePath . '.disabled']);
                $shouldRestart = true;
            }
        }

        if ($shouldRestart) {
            $this->restartWebserver();
        }
    }

    public function customRules(): string
    {
        $path = $this->customRulesPath();

        return $this->system->filesystem()->fileExists($path)
            ? $this->system->filesystem()->fileGetContents($path)
            : '';
    }

    public function customRulesEnabled(): bool
    {
        return in_array(self::CUSTOM_RULESET, Setting::getModsecConfig()['enabled_rulesets'], true);
    }

    /**
     * Replace the custom rules, but only once the webserver's own config test
     * has parsed them together with every enabled rule set. A rule the test
     * refuses never reaches the live file, so a reload cannot fail on it.
     *
     * @throws \InvalidArgumentException with what is wrong with the rules
     */
    public function saveCustomRules(string $rules): void
    {
        $problem = self::customRulesProblem($rules);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        $webserver = $this->currentWebserver();
        $testDir = $this->system->engineDirPath() . '/config/modsecurity/.custom-rules-test-' . bin2hex(random_bytes(6));
        try {
            $candidate = $testDir . '/' . self::CUSTOM_RULES_FILE;
            $this->system->filesystem()->writeFileReplacingPath($candidate, $rules);
            $this->system->filesystem()->writeFileReplacingPath($testDir . '/main.conf', $this->renderMainConf('DetectionOnly', $candidate));

            $problem = $this->configTest($webserver, $testDir);
        } finally {
            $this->system->runProcess(['sudo', 'rm', '-rf', $testDir]);
        }
        if ($problem !== null) {
            throw new \InvalidArgumentException('The webserver refused the rules: ' . $problem);
        }

        $this->system->filesystem()->writeFileReplacingPath($this->customRulesPath(), $rules);

        if ($this->customRulesEnabled() && Setting::getModsecConfig()['mode'] !== 'off') {
            $this->restartWebserver();
        }
    }

    /**
     * What makes the rules unacceptable before any webserver sees them, or null.
     */
    public static function customRulesProblem(string $rules): ?string
    {
        if (strlen($rules) > self::CUSTOM_RULES_MAX_BYTES) {
            return 'The rules are larger than ' . (self::CUSTOM_RULES_MAX_BYTES / 1024) . ' KB.';
        }

        // A backslash at the end of a line continues the directive on the next one.
        $logical = preg_split('/\R/', (string) preg_replace('/\\\\\R/', ' ', $rules)) ?: [];
        $ids = [];
        foreach ($logical as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $directive = strtolower((string) strtok($line, " \t"));
            if (!in_array($directive, self::CUSTOM_DIRECTIVES, true)) {
                return "Only rule directives are accepted (SecRule, SecAction, SecMarker, SecRuleRemoveBy*, SecRuleUpdate*By*), not {$directive}.";
            }
            // Errs towards refusing: an `id:` written inside a msg is checked too.
            preg_match_all('/(?:^|[\s",])id\s*:\s*\'?\s*(\d+)/i', $line, $m);
            foreach ($m[1] as $id) {
                $id = (int) $id;
                if ($id < self::CUSTOM_ID_MIN || $id > self::CUSTOM_ID_MAX) {
                    return "Rule id {$id} is outside the range kept for custom rules, "
                        . self::CUSTOM_ID_MIN . '-' . self::CUSTOM_ID_MAX . '.';
                }
                if (isset($ids[$id])) {
                    return "Rule id {$id} is used twice.";
                }
                $ids[$id] = true;
            }
        }

        return null;
    }

    /**
     * The webserver's config test over a minimal config that loads only
     * ModSecurity and $testDir/main.conf, run inside sites-http so it parses
     * with the same module and paths as the live server.
     */
    public static function configTestCommand(string $webserver, string $testDir): ?string
    {
        return match ($webserver) {
            'nginx', 'nginx-proxy' => 'nginx -t -c ' . escapeshellarg($testDir . '/nginx.conf'),
            'apache' => 'httpd -t -f ' . escapeshellarg($testDir . '/httpd.conf'),
            default => null,
        };
    }

    public static function configTestConfig(string $webserver, string $testDir): string
    {
        $rules = $testDir . '/main.conf';
        if ($webserver === 'apache') {
            return implode("\n", [
                'ServerRoot "/usr/local/apache2"',
                'ServerName localhost',
                'Listen 127.0.0.1:65535',
                'LoadModule mpm_event_module modules/mod_mpm_event.so',
                'LoadModule authz_core_module modules/mod_authz_core.so',
                'LoadModule unixd_module modules/mod_unixd.so',
                'LoadModule security3_module /usr/lib/apache2/modules/mod_security3.so',
                'ErrorLog /dev/stderr',
                'modsecurity on',
                "modsecurity_rules_file {$rules}",
                '',
            ]);
        }

        return implode("\n", [
            'load_module modules/ngx_http_modsecurity_module.so;',
            'error_log stderr;',
            'events {}',
            'http {',
            '    modsecurity on;',
            "    modsecurity_rules_file {$rules};",
            '}',
            '',
        ]);
    }

    /**
     * The line the test refused on, or null when it passed.
     */
    public static function configTestProblem(?int $exitCode, string $output): ?string
    {
        if ($exitCode === 0) {
            return null;
        }
        // nginx prints its refusal twice, once through error_log with a timestamp.
        $lines = preg_split('/\R/', $output) ?: [];
        foreach (['nginx: [emerg]', '[emerg]', 'Syntax error', 'Rules error'] as $marker) {
            foreach ($lines as $line) {
                if (str_contains($line, $marker)) {
                    return Str::limit(trim($line), 500);
                }
            }
        }
        $output = trim($output);

        return $output !== '' ? Str::limit($output, 500) : 'the config test failed without saying why';
    }

    /**
     * @return ?string the problem, or null when the rules parse
     */
    protected function configTest(string $webserver, string $testDir): ?string
    {
        $command = self::configTestCommand($webserver, $testDir);
        if ($command === null) {
            return "custom rules cannot be checked on the {$webserver} webserver, so they are not applied";
        }
        $this->system->filesystem()->writeFileReplacingPath(
            $testDir . ($webserver === 'apache' ? '/httpd.conf' : '/nginx.conf'),
            self::configTestConfig($webserver, $testDir)
        );

        $process = $this->system->runProcessOnHost(
            'sudo docker compose -f ' . escapeshellarg($this->system->composeFilePath())
                . ' exec -T sites-http ' . $command,
            [],
            self::CONFIG_TEST_TIMEOUT
        );

        return self::configTestProblem(
            $process->getExitCode(),
            $process->getErrorOutput() . "\n" . $process->getOutput()
        );
    }

    protected function currentWebserver(): string
    {
        try {
            return $this->system->webserver()->detectWebserver();
        } catch (\Exception $e) {
            return '';
        }
    }

    /** custom.conf, or custom.conf.disabled when the file was switched off through toggleConfigFiles(). */
    private function customRulesPath(): string
    {
        $path = $this->system->engineDirPath() . '/config/modsecurity/rulesets/' . self::CUSTOM_RULESET
            . '/rules/' . self::CUSTOM_RULES_FILE;

        return !$this->system->filesystem()->fileExists($path)
            && $this->system->filesystem()->fileExists($path . '.disabled')
            ? $path . '.disabled'
            : $path;
    }

    /**
     * @param array<string> $cmd
     */
    private function runProcessOrFail(array $cmd): void
    {
        $process = $this->system->runProcess($cmd);
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException($message !== '' ? $message : 'ModSecurity config file toggle failed');
        }
    }
}
