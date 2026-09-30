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
        $mainConfTemplatePath = $this->system->engineDirPath() . '/templates/config/modsecurity-main.conf.blade.php';
        $mainConfTemplate = $this->system->filesystem()->fileGetContents($mainConfTemplatePath);

        $mode = 'Off';
        switch ($config['mode']) {
            case 'on':
                $mode = 'On';
                break;
            case 'detection_only':
                $mode = 'DetectionOnly';
                break;
        }

        $includeFiles = [];
        foreach ($config['enabled_rulesets'] as $ruleset) {
            $dir = $this->system->engineDirPath() . '/config/modsecurity/rulesets/' . $ruleset;
            if (!is_dir($dir)) {
                continue;
            }
            if (is_file($dir . '/setup.conf')) {
                $includeFiles[] = $dir . '/setup.conf';
            }
            $includeFiles[] = $dir . '/rules/*.conf';
        }

        $templateVars = [
            'SecRuleEngine' => $mode,
            'includeFiles' => $includeFiles,
        ];
        $mainConf = Blade::render($mainConfTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($mainConfPath, $mainConf);

        $this->system->webserver()->driver()->toggleModsecurity();
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
