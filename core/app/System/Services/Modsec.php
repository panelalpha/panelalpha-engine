<?php

namespace App\System\Services;

use App\Models\Setting;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Privileged\RootEngineServices;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Host ModSecurity rulesets under the Engine install config. rebuildConfig writes main.conf, then the driver's
 * toggleModsecurity (a vhost rewrite, not on App\System\Webserver yet) reloads or restarts sites-http.
 */
class Modsec
{
    /** A rule file name as toggleConfigFiles() accepts it: as listed, with or without `.disabled`. */
    public const CONFIG_FILE_NAME = '/\A[A-Za-z0-9_][A-Za-z0-9._-]*\.conf(?:\.disabled)?\z/';

    /** The rule set the operator writes through the API; enabled and included like the shipped ones. */
    public const CUSTOM_RULESET = 'custom';

    public const CUSTOM_RULES_FILE = 'custom.conf';

    /** Held while a rule set is switched: the setting is read, written and applied as one step. */
    public const RULESETS_LOCK = 'modsec-rulesets';

    /** Long enough for a webserver restart, which the switch waits for. */
    private const RULESETS_LOCK_SECONDS = 120;

    /**
     * Rule ids a custom rule may take: clear of OWASP CRS (900000-999999) and
     * of the engine's own rules (1000000-1009999).
     */
    public const CUSTOM_ID_MIN = 1100000;

    public const CUSTOM_ID_MAX = 1199999;

    public const CUSTOM_RULES_MAX_BYTES = 262144;

    /**
     * Rules only: no Include, no log or data paths, no remote rule sources. Each with the arguments it takes;
     * libmodsecurity ends the directive after them and reads what follows as another one. null: the rest of the line.
     */
    private const CUSTOM_DIRECTIVES = [
        'secrule' => 3,
        'secaction' => 1,
        'secmarker' => 1,
        'secruleremovebyid' => null,
        'secruleremovebytag' => null,
        'secruleremovebymsg' => null,
        'secruleupdateactionbyid' => 2,
        'secruleupdatetargetbyid' => 2,
        'secruleupdatetargetbytag' => 2,
        'secruleupdatetargetbymsg' => 2,
    ];

    /**
     * Every directive libmodsecurity 3 recognises, matched as a prefix: its lexer takes one with no separator after it
     * (`SecRuleEngineOff`). Quotes do not hide them, as the static checks do not read quotes exactly as it does.
     */
    private const DIRECTIVE_KEYWORDS = [
        'include', 'secaction', 'secargumentseparator', 'secargumentslimit', 'secauditengine',
        'secauditlog', 'secauditlog2', 'secauditlogdirmode', 'secauditlogfilemode', 'secauditlogformat',
        'secauditlogparts', 'secauditlogprefix', 'secauditlogrelevantstatus', 'secauditlogstoragedir',
        'secauditlogtype', 'seccachetransformations', 'secchrootdir', 'seccollectiontimeout',
        'seccomponentsignature', 'secconnengine', 'secconnreadstatelimit', 'secconnwritestatelimit',
        'seccontentinjection', 'seccookieformat', 'seccookiev0separator', 'secdatadir', 'secdebuglog',
        'secdebugloglevel', 'secdefaultaction', 'secdisablebackendcompression', 'secgeolookupdb',
        'secgsblookupdb', 'secguardianlog', 'sechashengine', 'sechashkey', 'sechashmethodpm',
        'sechashmethodrx', 'sechashparam', 'sechttpblkey', 'secinterceptonerror', 'secmarker',
        'secparsexmlintoargs', 'secpcrematchlimit', 'secpcrematchlimitrecursion', 'secremoterules',
        'secremoterulesfailaction', 'secrequestbodyaccess', 'secrequestbodyinmemorylimit',
        'secrequestbodyjsondepthlimit', 'secrequestbodylimit', 'secrequestbodylimitaction',
        'secrequestbodynofileslimit', 'secresponsebodyaccess', 'secresponsebodylimit',
        'secresponsebodylimitaction', 'secresponsebodymimetype', 'secresponsebodymimetypesclear',
        'secrule', 'secruleengine', 'secruleinheritance', 'secruleperftime', 'secruleremovebyid',
        'secruleremovebymsg', 'secruleremovebytag', 'secrulescript', 'secruleupdateactionbyid',
        'secruleupdatetargetbyid', 'secruleupdatetargetbymsg', 'secruleupdatetargetbytag', 'secsensorid',
        'secserversignature', 'secstatusengine', 'secstreaminbodyinspection', 'secstreamoutbodyinspection',
        'sectmpdir', 'sectmpsaveuploadedfiles', 'secunicodemapfile', 'secuploaddir', 'secuploadfilelimit',
        'secuploadfilemode', 'secuploadkeepfiles', 'secwebappid', 'secxmlexternalentity',
    ];

    /**
     * The operators libmodsecurity 3's rule parser knows, matched case-insensitively. Any other `@name` it reads as a
     * regular expression, so a misspelt one parses and then matches nothing (negated, everything).
     */
    private const OPERATORS = [
        'beginswith', 'contains', 'containsword', 'detectsqli', 'detectxss', 'endswith', 'eq',
        'fuzzyhash', 'ge', 'geolookup', 'gsblookup', 'gt', 'inspectfile', 'ipmatch', 'ipmatchf',
        'ipmatchfromfile', 'le', 'lt', 'pm', 'pmf', 'pmfromfile', 'rbl', 'rsub', 'rx', 'rxglobal',
        'streq', 'strmatch', 'unconditionalmatch', 'validatebyterange', 'validatedtd', 'validatehash',
        'validateschema', 'validateurlencoding', 'validateutf8encoding', 'verifycc', 'verifycpf',
        'verifyssn', 'verifysvnr', 'within',
    ];

    /**
     * The operators libmodsecurity reads only when nothing but spaces follows them
     * up to the closing quote; with an argument it reads the whole text as a regex.
     */
    private const NO_ARG_OPERATORS = [
        'detectsqli', 'detectxss', 'geolookup', 'unconditionalmatch',
        'validateurlencoding', 'validateutf8encoding',
    ];

    /** How long the webserver's config test may take; CRS alone is a few thousand rules to parse. */
    private const CONFIG_TEST_TIMEOUT = 60;

    /**
     * The scratch check: a rule before the candidate, then one per phase a rule can stop the later ones in (not the
     * response-body phase, which does not run) and one on a request body, each denying one request.
     */
    private const CHECK_PATH = '/panelalpha-modsecurity-check/';

    /** No real rule answers 418, so that answer proves the check's rule denied the request. */
    private const CHECK_STATUS = '418';

    private const CHECK_BODY_ARG = 'panelalpha_check';

    /** The check rules' requests, then `plain`: a browser GET for /, which nothing should deny. */
    private const CHECK_REQUESTS = ['first', 'phase1', 'phase2', 'phase3', 'body', 'plain'];

    public function __construct(
        private EngineSystem $system,
    ) {}

    /** Every shipped rule set, then the custom one, listed even before it holds any rules. */
    public function getRulesets(): array
    {
        $dir = $this->system->engineDirPath().'/config/modsecurity/rulesets';

        $rulesets = [];
        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $set) {
            if (basename($set) !== self::CUSTOM_RULESET && is_dir($set.'/rules')) {
                $rulesets[] = self::ruleset($set);
            }
        }
        $rulesets[] = self::ruleset($dir.'/'.self::CUSTOM_RULESET);

        return $rulesets;
    }

    /** @return array{name: string, config_files: list<string>} */
    private static function ruleset(string $dir): array
    {
        $files = [];
        foreach (glob($dir.'/rules/*.conf*') ?: [] as $file) {
            if (is_file($file) && Str::endsWith($file, ['.conf', '.conf.disabled'])) {
                $files[] = basename($file);
            }
        }

        return [
            'name' => basename($dir),
            'config_files' => $files,
        ];
    }

    public function rulesetExists(string $name): bool
    {
        $dir = $this->system->engineDirPath().'/config/modsecurity/rulesets/'.$name;

        return $name === self::CUSTOM_RULESET || is_dir($dir);
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
        $dir = $this->system->engineDirPath().'/logs/modsecurity';
        if (! is_dir($dir)) {
            return [];
        }
        $files = [];
        $fileNames = [];

        foreach (scandir($dir) as $file) {
            if (! is_file($dir.'/'.$file)) {
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
        $this->writeMainConf();

        $this->system->webserver()->driver()->toggleModsecurity();
    }

    /**
     * Switches one rule set under a lock, so concurrent calls cannot write each other's change away.
     * When the webserver does not take the change, the setting and main.conf go back, so a retry applies it again.
     */
    public function setRulesetEnabled(string $name, bool $enabled): void
    {
        Cache::lock(self::RULESETS_LOCK, self::RULESETS_LOCK_SECONDS)
            ->block(self::RULESETS_LOCK_SECONDS, fn () => $this->switchRuleset($name, $enabled));
    }

    private function switchRuleset(string $name, bool $enabled): void
    {
        $previous = Setting::getModsecConfig()['enabled_rulesets'];
        if (in_array($name, $previous, true) === $enabled) {
            return;
        }

        Setting::setModsecConfig('enabled_rulesets', $enabled
            ? [...$previous, $name]
            : array_values(array_diff($previous, [$name])));
        try {
            $this->rebuildConfig();
        } catch (\Throwable $e) {
            Setting::setModsecConfig('enabled_rulesets', $previous);
            $this->writeMainConf();
            throw $e;
        }
    }

    private function writeMainConf(): void
    {
        $config = Setting::getModsecConfig();

        $mainConfPath = $this->system->engineDirPath().'/config/modsecurity/main.conf';

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
    }

    /**
     * main.conf over the enabled rule sets. With $customRules, that file stands in for the custom rule set,
     * enabled or not -- what main.conf will be once the candidate rules are live.
     */
    private function renderMainConf(string $mode, ?string $customRules = null): string
    {
        $config = Setting::getModsecConfig();
        $mainConfTemplatePath = $this->system->engineDirPath().'/templates/config/modsecurity-main.conf.blade.php';
        $mainConfTemplate = $this->system->filesystem()->fileGetContents($mainConfTemplatePath);

        $rulesets = $config['enabled_rulesets'];
        $includeFiles = $this->mainConfIncludes($rulesets, $customRules);

        return Blade::render($mainConfTemplate, [
            'SecRuleEngine' => $mode,
            'includeFiles' => $includeFiles,
        ]);
    }

    /**
     * $customRules goes where the live config loads the custom set, as what it does to CRS depends on whether it
     * comes before it; enabled later, a rule set is appended.
     *
     * @param  list<string>  $rulesets
     * @return list<string>
     */
    private function mainConfIncludes(array $rulesets, ?string $customRules): array
    {
        if ($customRules !== null && ! in_array(self::CUSTOM_RULESET, $rulesets, true)) {
            $rulesets[] = self::CUSTOM_RULESET;
        }
        $includeFiles = [];
        foreach ($rulesets as $ruleset) {
            if ($customRules !== null && $ruleset === self::CUSTOM_RULESET) {
                $includeFiles[] = $customRules;

                continue;
            }
            $dir = $this->system->engineDirPath().'/config/modsecurity/rulesets/'.$ruleset;
            if (! is_dir($dir)) {
                continue;
            }
            if (is_file($dir.'/setup.conf')) {
                $includeFiles[] = $dir.'/setup.conf';
            }
            $includeFiles[] = $dir.'/rules/*.conf';
        }

        return $includeFiles;
    }

    /**
     * ModSecurity rule files are loaded when the webserver process starts. Full restart is required on OLS/Apache;
     * nginx in Docker must reload instead, as an init.d restart exits PID 1 and loops the container.
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
     * Names are joined to a path and renamed as root, where a `../` would rename any file. getRulesets() lists
     * a disabled file as `X.conf.disabled`; both names mean X.conf.
     *
     * @param  array<string>  $enable
     * @param  array<string>  $disable
     */
    public function toggleConfigFiles(string $ruleset, array $enable = [], array $disable = []): void
    {
        foreach ([$ruleset, ...$enable, ...$disable] as $name) {
            if ($name !== basename($name) || $name === '..' || $name === '.') {
                throw new \InvalidArgumentException("Invalid ModSecurity file name: {$name}");
            }
        }

        $dir = $this->system->engineDirPath().'/config/modsecurity/rulesets/'.$ruleset.'/rules';
        if (! is_dir($dir)) {
            return;
        }

        $canonical = fn (string $name): string => preg_replace('/\.disabled\z/', '', $name);
        $known = array_map($canonical, self::ruleset(dirname($dir))['config_files']);
        foreach ([...$enable, ...$disable] as $name) {
            if (! in_array($canonical($name), $known, true)) {
                throw new \InvalidArgumentException("Unknown ModSecurity config file: {$name}");
            }
        }

        $enable = array_map($canonical, $enable);
        $disable = array_map($canonical, $disable);

        $moves = $this->renameToggledFiles($dir, $enable, $disable);

        if ($moves !== []) {
            $this->liveConfigTestOrMoveBack($ruleset, $moves);
            $this->restartWebserver();
        }
    }

    /**
     * @param  array<string>  $enable
     * @param  array<string>  $disable
     * @return list<array{0: string, 1: string}> each rename done, from => to
     */
    private function renameToggledFiles(string $dir, array $enable, array $disable): array
    {
        $moves = [];
        foreach ($enable as $filename) {
            $filePath = $dir.'/'.$filename;
            if (is_file($filePath.'.disabled')) {
                $this->runProcessOrFail(Privileged::files()->move($filePath.'.disabled', $filePath));
                $moves[] = [$filePath.'.disabled', $filePath];
            }
        }

        foreach ($disable as $filename) {
            $filePath = $dir.'/'.$filename;
            if (is_file($filePath)) {
                $this->runProcessOrFail(Privileged::files()->move($filePath, $filePath.'.disabled'));
                $moves[] = [$filePath, $filePath.'.disabled'];
            }
        }

        return $moves;
    }

    /**
     * A loaded rule set's files after the renames must still load: the last file disabled leaves main.conf an include
     * glob libmodsecurity refuses, and an enabled file may not parse. Either way the files go back and nothing restarts.
     * A rule set that is not loaded, or a webserver with no config test, is renamed as before.
     *
     * @param  list<array{0: string, 1: string}>  $moves
     *
     * @throws \InvalidArgumentException with what the webserver said
     */
    private function liveConfigTestOrMoveBack(string $ruleset, array $moves): void
    {
        if (! $this->loadsRuleset($ruleset)) {
            return;
        }
        $webserver = $this->currentWebserver();
        if (self::liveConfigTestCommand($webserver) === null) {
            return;
        }

        try {
            $problem = $this->liveConfigTest($webserver);
        } catch (\Throwable $e) {
            $this->moveBack($moves);
            throw $e;
        }
        if ($problem !== null) {
            $this->moveBack($moves);
            throw new \InvalidArgumentException('The webserver would not load the rule set with these files, so nothing changed: '.$problem);
        }
    }

    private function loadsRuleset(string $ruleset): bool
    {
        $config = Setting::getModsecConfig();

        return $config['mode'] !== 'off' && in_array($ruleset, $config['enabled_rulesets'], true);
    }

    /** @param  list<array{0: string, 1: string}>  $moves */
    private function moveBack(array $moves): void
    {
        foreach (array_reverse($moves) as [$from, $to]) {
            $this->runProcessOrFail(Privileged::files()->move($to, $from));
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
     * Replace the custom rules once the webserver's config test parsed them with every enabled rule set (nginx also
     * runs them, see configTest()) and, if loaded, its live config passed: no refused rule stays to fail a reload.
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
        $problem = $this->scratchConfigTest($webserver, $rules);
        if ($problem !== null) {
            throw new \InvalidArgumentException('The webserver refused the rules: '.$problem);
        }

        $live = $this->customRulesPath();
        $previous = $this->system->filesystem()->fileExists($live)
            ? $this->system->filesystem()->fileGetContents($live)
            : null;
        $this->system->filesystem()->writeFileReplacingPath($live, $rules);

        if (! $this->customRulesEnabled() || Setting::getModsecConfig()['mode'] === 'off') {
            return;
        }

        $this->liveConfigTestOrRestore($webserver, $live, $previous);

        $this->restartWebserver();
    }

    /** The scratch main.conf has the engine On, so the check sees whether the candidate turns it off. */
    private function scratchConfigTest(string $webserver, string $rules): ?string
    {
        $testDir = $this->system->engineDirPath().'/config/modsecurity/.custom-rules-test-'.bin2hex(random_bytes(6));
        try {
            $candidate = $testDir.'/'.self::CUSTOM_RULES_FILE;
            $this->system->filesystem()->writeFileReplacingPath($candidate, $rules);
            $this->system->filesystem()->writeFileReplacingPath($testDir.'/main.conf', $this->renderMainConf('On', $candidate));

            $problem = $this->configTest($webserver, $testDir);
        } finally {
            $this->system->runProcess(Privileged::files()->removeTree($testDir));
        }

        return $problem;
    }

    /**
     * A data file named relative to the rules resolves next to the scratch copy, not here. A failing live config, or
     * a test that timed out or could not run, would stop a reload applying and a restart starting: old rules go back.
     */
    private function liveConfigTestOrRestore(string $webserver, string $live, ?string $previous): void
    {
        try {
            $problem = $this->liveConfigTest($webserver);
        } catch (\Throwable $e) {
            $this->restoreCustomRules($live, $previous);
            throw $e;
        }
        if ($problem !== null) {
            $this->restoreCustomRules($live, $previous);
            throw new \InvalidArgumentException('The webserver refused the rules in place, so the previous rules stay live: '.$problem);
        }
    }

    /** A silent rm failure would leave the refused rules live, so it throws. */
    private function restoreCustomRules(string $live, ?string $previous): void
    {
        if ($previous === null) {
            $this->runProcessOrFail(Privileged::files()->removeIfExists($live));
        } else {
            $this->system->filesystem()->writeFileReplacingPath($live, $previous);
        }
    }

    /**
     * What makes the rules unacceptable before any webserver sees them, or null.
     */
    public static function customRulesProblem(string $rules): ?string
    {
        if (strlen($rules) > self::CUSTOM_RULES_MAX_BYTES) {
            return 'The rules are larger than '.(self::CUSTOM_RULES_MAX_BYTES / 1024).' KB.';
        }

        $problem = self::ruleEngineProblem($rules);
        if ($problem !== null) {
            return $problem;
        }

        $logical = self::logicalLines($rules);
        if (is_string($logical)) {
            return $logical;
        }

        return self::directivesProblem($logical);
    }

    /**
     * Anywhere, even in a comment or a message: the mode is the setting for every site, and ctl:ruleEngine in a rule
     * that matches only some requests switches off every rule after it for those.
     */
    private static function ruleEngineProblem(string $rules): ?string
    {
        foreach ([$rules, preg_replace('/\\\\\R[ \t]*/', '', $rules)] as $text) {
            if (stripos((string) $text, 'ruleengine') !== false) {
                return 'SecRuleEngine and ctl:ruleEngine are not accepted in custom rules, in any spelling, not even in a comment '
                    .'or a message. The engine\'s mode is the ModSecurity setting for every site; a rule that changes it '
                    .'switches protection off or to detection-only. To exempt requests from a rule, use ctl:ruleRemoveById.';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $logical
     */
    private static function directivesProblem(array $logical): ?string
    {
        $keywords = self::directiveKeywordPattern();
        $ids = [];
        $markers = [];
        $skipAfters = [];
        foreach ($logical as $at => $line) {
            $problem = self::lineProblem(trim($line), $at, $keywords, $ids, $markers, $skipAfters);
            if ($problem !== null) {
                return $problem;
            }
        }

        return self::skipAfterProblem($skipAfters, $markers);
    }

    /**
     * @param  array<int, true>  $ids
     * @param  array<string, int>  $markers
     * @param  list<array{0: string, 1: int}>  $skipAfters
     */
    private static function lineProblem(
        string $line,
        int $at,
        string $keywords,
        array &$ids,
        array &$markers,
        array &$skipAfters,
    ): ?string {
        if ($line === '') {
            return null;
        }
        $tokens = self::tokenize($line);
        if ($tokens === []) {
            return null;
        }

        $problem = self::directiveProblem($line, $tokens, $keywords) ?? self::idProblem($line, $ids);
        if ($problem !== null) {
            return $problem;
        }
        $directive = strtolower($tokens[0]['value']);
        if ($directive === 'secrule') {
            $problem = self::operatorProblem($tokens);
            if ($problem !== null) {
                return $problem;
            }
        }

        return self::skipProblem($line, $directive, $tokens, $at, $markers, $skipAfters);
    }

    /**
     * libmodsecurity ends a directive at the close of its actions, a marker name or a bare backslash, and reads another
     * one straight after it, glued on or not. A directive name anywhere but the start of a line is refused.
     *
     * @param  list<array{value: string, quoted: bool, closed: bool}>  $tokens
     */
    private static function directiveProblem(string $line, array $tokens, string $keywords): ?string
    {
        preg_match_all($keywords, $line, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $offset]) {
            if ($offset !== 0) {
                return 'A line may hold only one directive, at its start. Put each SecRule, SecAction or SecMarker on '
                    .'its own line (a chained rule is several lines, each a SecRule). A directive name may not appear '
                    .'anywhere else on the line, not even inside quotes: "'.Str::limit($line, 80).'"';
            }
        }

        if ($tokens[0]['quoted']) {
            return 'A directive must not be quoted.';
        }
        $directive = strtolower($tokens[0]['value']);
        if (! array_key_exists($directive, self::CUSTOM_DIRECTIVES)) {
            return "Only rule directives are accepted (SecRule, SecAction, SecMarker, SecRuleRemoveBy*, SecRuleUpdate*By*), not {$directive}.";
        }
        if (! self::argumentsFit($directive, array_slice($tokens, 1))) {
            return 'There is more on this line than its directive takes, and ModSecurity would read the rest as another '
                .'directive. Only a # comment may follow the last argument: "'.Str::limit($line, 80).'"';
        }

        return null;
    }

    /**
     * Errs towards refusing: an `id:` written inside a msg is checked too.
     *
     * @param  array<int, true>  $ids
     */
    private static function idProblem(string $line, array &$ids): ?string
    {
        preg_match_all('/(?:^|[\s",])id\s*:\s*\'?\s*(\d+)/i', $line, $m);
        foreach ($m[1] as $id) {
            $id = (int) $id;
            if ($id < self::CUSTOM_ID_MIN || $id > self::CUSTOM_ID_MAX) {
                return "Rule id {$id} is outside the range kept for custom rules, "
                    .self::CUSTOM_ID_MIN.'-'.self::CUSTOM_ID_MAX.'.';
            }
            if (isset($ids[$id])) {
                return "Rule id {$id} is used twice.";
            }
            $ids[$id] = true;
        }

        return null;
    }

    /**
     * skip:N counts the rules ModSecurity runs next, in every rule set and on into later phases, so where it lands
     * depends on what is loaded around these. libmodsecurity drops single quotes around a marker name as well.
     *
     * @param  list<array{value: string, quoted: bool, closed: bool}>  $tokens
     * @param  array<string, int>  $markers
     * @param  list<array{0: string, 1: int}>  $skipAfters
     */
    private static function skipProblem(
        string $line,
        string $directive,
        array $tokens,
        int $at,
        array &$markers,
        array &$skipAfters,
    ): ?string {
        if (preg_match('/(?<![a-z0-9_])skip\s*:/i', $line)) {
            return 'skip is not accepted in custom rules: it counts the rules ModSecurity runs next, in every rule set '
                .'and into later phases. Use skipAfter with a SecMarker after the rules to skip.';
        }
        if ($directive === 'secmarker') {
            $markers[preg_replace("/\\A'(.*)'\\z/s", '$1', $tokens[1]['value'] ?? '')] = $at;
        }
        if (preg_match_all('/(?<![a-z0-9_])skipafter\s*:\s*\'?([^\'",\s]*)/i', $line, $m)) {
            if (! in_array($directive, ['secrule', 'secaction'], true)) {
                return 'skipAfter is accepted only in a SecRule or SecAction, before the SecMarker it names.';
            }
            foreach ($m[1] as $name) {
                $skipAfters[] = [$name, $at];
            }
        }

        return null;
    }

    /**
     * A marker that is missing, misspelt or before the rule makes ModSecurity skip every rule it reaches until that
     * name: in every rule set and later phases.
     *
     * @param  list<array{0: string, 1: int}>  $skipAfters
     * @param  array<string, int>  $markers
     */
    private static function skipAfterProblem(array $skipAfters, array $markers): ?string
    {
        foreach ($skipAfters as [$name, $at]) {
            if (($markers[$name] ?? -1) <= $at) {
                return "skipAfter:{$name} names no SecMarker after it in these rules (names are case-sensitive). ModSecurity "
                    .'would skip rules outside these, in every rule set and in later phases, for every request it matches. '
                    ."Put SecMarker {$name} after the rules it should skip.";
            }
        }

        return null;
    }

    /**
     * The directives with backslash continuations joined and comments left out,
     * or why the comments cannot be read safely.
     *
     * @return list<string>|string
     */
    private static function logicalLines(string $rules): array|string
    {
        $physical = preg_split('/\R/', $rules) ?: [];
        $count = count($physical);
        $logical = [];
        for ($n = 0; $n < $count; $n++) {
            $trimmed = trim($physical[$n]);
            if ($trimmed === '') {
                continue;
            }
            if ($trimmed[0] === '#') {
                $end = self::commentEnd($physical, $count, $n);
                if (is_string($end)) {
                    return $end;
                }
                $n = $end;

                continue;
            }
            $joined = self::joinedDirective($physical, $count, $n);
            if (is_string($joined)) {
                return $joined;
            }
            [$current, $n] = $joined;
            $logical[] = $current;
        }

        return $logical;
    }

    /**
     * libmodsecurity carries a commented-out SecRule/SecAction ending in \ onto the following lines as comment, so a
     * directive among them would never load and is refused. Any other comment, ending in \ or not, ends at the newline.
     *
     * @param  list<string>  $physical
     */
    private static function commentEnd(array $physical, int $count, int $n): int|string
    {
        if (preg_match('/^[ \t]*#[ \t]*sec(?:rule|action)[^\\\\].*\\\\[ \t]*$/i', $physical[$n])) {
            for ($m = $n + 1; $m < $count; $m++) {
                $swallowed = trim($physical[$m]);
                if ($swallowed === '') {
                    continue;
                }
                if ($swallowed[0] !== '#') {
                    return 'Line '.($n + 1).' is a commented-out SecRule/SecAction that ends in \\. '
                        .'ModSecurity reads line '.($m + 1).' as part of the comment, so that rule would never load. '
                        .'Comment that line too, or remove the backslash.';
                }
                if (! str_ends_with(rtrim($physical[$m]), '\\')) {
                    break;
                }
            }
            $n = $m;
        }

        return $n;
    }

    /**
     * A backslash ending a directive's line continues it on the next one, but not once the directive is complete:
     * libmodsecurity then reads the next line as a directive of its own, and joining would hide it.
     *
     * @param  list<string>  $physical
     * @return array{0: string, 1: int}|string
     */
    private static function joinedDirective(array $physical, int $count, int $n): array|string
    {
        $current = $physical[$n];
        while ($n + 1 < $count && str_ends_with(rtrim($current), '\\')) {
            $head = substr(rtrim($current), 0, -1);
            if (self::isComplete($head)) {
                return 'Line '.($n + 1).' ends in \\ after a complete directive. ModSecurity does not carry a '
                    .'finished directive onto the next line; it reads that line as a directive of its own. '
                    .'Remove the backslash.';
            }
            $current = $head.' '.$physical[$n + 1];
            $n++;
        }

        return [$current, $n];
    }

    /** A directive with all the arguments it takes, the last one closed. */
    private static function isComplete(string $text): bool
    {
        $tokens = self::tokenize(trim($text));
        if ($tokens === [] || $tokens[0]['quoted'] || ! $tokens[count($tokens) - 1]['closed']) {
            return false;
        }
        $directive = strtolower($tokens[0]['value']);
        if (! array_key_exists($directive, self::CUSTOM_DIRECTIVES)) {
            return false;
        }
        $takes = self::CUSTOM_DIRECTIVES[$directive];

        return count($tokens) - 1 >= ($takes ?? 1);
    }

    /**
     * Whether the arguments are no more than the directive takes, a trailing # comment aside. A directive taking
     * the rest of the line takes one quoted value or bare words.
     *
     * @param  list<array{value: string, quoted: bool, closed: bool}>  $arguments
     */
    private static function argumentsFit(string $directive, array $arguments): bool
    {
        $taken = [];
        foreach ($arguments as $argument) {
            if (! $argument['quoted'] && str_starts_with($argument['value'], '#')) {
                break;
            }
            $taken[] = $argument;
        }
        $takes = self::CUSTOM_DIRECTIVES[$directive];
        if ($takes !== null) {
            return count($taken) <= $takes;
        }

        return count($taken) <= 1 || array_filter($taken, fn (array $a): bool => $a['quoted']) === [];
    }

    /**
     * Any directive name, as libmodsecurity's lexer would take it where a directive may start. Include alone
     * needs a blank after it; the Sec* names take nothing.
     */
    private static function directiveKeywordPattern(): string
    {
        $words = self::DIRECTIVE_KEYWORDS;
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $words = array_map(fn (string $w): string => $w === 'include' ? 'include(?=[ \t])' : $w, $words);

        return '/(?<![A-Za-z0-9_])(?:'.implode('|', $words).')/i';
    }

    /**
     * A directive's arguments in order, each marked quoted or bare.
     *
     * @return list<array{value: string, quoted: bool, closed: bool}>
     */
    private static function tokenize(string $line): array
    {
        $tokens = [];
        $len = strlen($line);
        $i = 0;
        while ($i < $len) {
            $c = $line[$i];
            if ($c === ' ' || $c === "\t") {
                $i++;

                continue;
            }
            if ($c === '"') {
                [$token, $i] = self::quotedToken($line, $len, $i);
                $tokens[] = $token;

                continue;
            }
            [$value, $i] = self::bareValue($line, $len, $i);
            if ($value !== '') {
                $tokens[] = ['value' => $value, 'quoted' => false, 'closed' => true];
            }
        }

        return $tokens;
    }

    /**
     * Spans one "...", with \" an escaped quote, and is not closed when the line ends inside it.
     *
     * @return array{0: array{value: string, quoted: bool, closed: bool}, 1: int}
     */
    private static function quotedToken(string $line, int $len, int $i): array
    {
        $i++;
        $value = '';
        $closed = false;
        while ($i < $len) {
            if ($line[$i] === '\\' && $i + 1 < $len) {
                $value .= $line[$i + 1];
                $i += 2;

                continue;
            }
            if ($line[$i] === '"') {
                $i++;
                $closed = true;
                break;
            }
            $value .= $line[$i];
            $i++;
        }

        return [['value' => $value, 'quoted' => true, 'closed' => $closed], $i];
    }

    /**
     * Runs to the next space, keeping any \" so an escaped quote inside a variable does not open a string.
     *
     * @return array{0: string, 1: int}
     */
    private static function bareValue(string $line, int $len, int $i): array
    {
        $value = '';
        while ($i < $len && $line[$i] !== ' ' && $line[$i] !== "\t" && $line[$i] !== '"') {
            if ($line[$i] === '\\' && $i + 1 < $len) {
                $value .= $line[$i].$line[$i + 1];
                $i += 2;

                continue;
            }
            $value .= $line[$i];
            $i++;
        }

        return [$value, $i];
    }

    /**
     * Why a SecRule's operator, its third argument, would not be read as the operator it names, or null. Without an @
     * it is an @rx, which is fine; libmodsecurity takes an operator only right after the quote or the `!`.
     *
     * @param  list<array{value: string, quoted: bool, closed: bool}>  $tokens
     */
    private static function operatorProblem(array $tokens): ?string
    {
        if (! isset($tokens[2])) {
            return null;
        }
        $operator = $tokens[2]['value'];
        if (! preg_match('/\A\s*!?\s*@/', $operator)) {
            return null;
        }
        $shown = Str::limit($operator, 100);
        if (! preg_match('/\A!?@(\w*)([\s\S]*)\z/', $operator, $m)) {
            return "The operator \"{$shown}\" has a space before the @, so ModSecurity would read it as a regular expression, "
                .'not as an operator. To match a literal @, use @rx.';
        }
        $name = strtolower($m[1]);
        if (! in_array($name, self::OPERATORS, true)) {
            return "Unknown operator @{$m[1]}. ModSecurity would read \"{$shown}\" as a regular expression, "
                .'so the rule would not do what it says. To match a literal @, use @rx.';
        }
        if ($tokens[2]['quoted'] && in_array($name, self::NO_ARG_OPERATORS, true) && trim($m[2]) !== '') {
            return "The operator @{$m[1]} takes no argument. ModSecurity would read \"{$shown}\" as a regular expression, "
                .'so the rule would not do what it says.';
        }

        return null;
    }

    public static function canTestOn(string $webserver): bool
    {
        return in_array($webserver, ['nginx', 'nginx-proxy', 'apache'], true);
    }

    public static function configTestConfig(string $webserver, string $testDir): string
    {
        if ($webserver === 'apache') {
            $rules = $testDir.'/main.conf';

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

        return self::nginxTestConfig($testDir);
    }

    /**
     * Started for a second next to the live server, so every file it writes stays in the test dir (a socket path holds
     * 107 bytes). try_files answers after ModSecurity's phases, `return` before the request-body one.
     */
    private static function nginxTestConfig(string $testDir): string
    {
        return implode("\n", [
            'load_module modules/ngx_http_modsecurity_module.so;',
            'daemon off;',
            'master_process off;',
            "pid {$testDir}/nginx.pid;",
            "lock_file {$testDir}/nginx.lock;",
            'error_log stderr;',
            'events {}',
            'http {',
            "    client_body_temp_path {$testDir}/temp;",
            "    proxy_temp_path {$testDir}/temp;",
            "    fastcgi_temp_path {$testDir}/temp;",
            "    uwsgi_temp_path {$testDir}/temp;",
            "    scgi_temp_path {$testDir}/temp;",
            '    access_log off;',
            '    modsecurity on;',
            "    modsecurity_rules_file {$testDir}/check.conf;",
            '    server {',
            "        listen unix:{$testDir}/check.sock;",
            '        location / {',
            "            root {$testDir}/www;",
            '            try_files $uri =204;',
            '        }',
            '    }',
            '}',
            '',
        ]);
    }

    /**
     * The candidate's main.conf between check rules with ids clear of CRS, the engine's and the custom range; what it
     * does to the mode is in force as live. SecAuditEngine Off keeps the check's requests out of the live audit log.
     */
    public static function checkRules(string $testDir): string
    {
        $deny = fn (int $id, int $phase, string $variable, string $operator): string => "SecRule {$variable} \"{$operator}\" "
            ."\"id:{$id},phase:{$phase},deny,status:".self::CHECK_STATUS.',nolog,noauditlog"';
        $path = fn (string $name): string => '@streq '.self::CHECK_PATH.$name;

        return implode("\n", [
            $deny(1099990, 1, 'REQUEST_URI', $path('first')),
            "Include {$testDir}/main.conf",
            'SecAuditEngine Off',
            $deny(1099991, 1, 'REQUEST_URI', $path('phase1')),
            $deny(1099992, 2, 'REQUEST_URI', $path('phase2')),
            $deny(1099993, 3, 'REQUEST_URI', $path('phase3')),
            $deny(1099994, 2, 'ARGS_POST:'.self::CHECK_BODY_ARG, '@streq 1'),
            '',
        ]);
    }

    /**
     * Why the check's answers say the rules switch protection off or deny every request, or null.
     */
    public static function checkProblem(string $output): ?string
    {
        preg_match_all('/^modsecurity-check (\w+) (\d{3})$/m', $output, $m, PREG_SET_ORDER);
        $answers = array_column($m, 2, 1);
        foreach (self::CHECK_REQUESTS as $request) {
            if (($answers[$request] ?? '000') === '000') {
                return 'the check of what the rules do could not run, so they are not applied';
            }
        }

        return self::answersProblem($answers);
    }

    /**
     * The first rule, loaded before the candidate, must still deny; a request a rule after it denies must not reach the
     * server's own answer (204); the plain one must: a set denying every request passes the rest and downs every site.
     *
     * @param  array<string, string>  $answers
     */
    private static function answersProblem(array $answers): ?string
    {
        if ($answers['first'] !== self::CHECK_STATUS) {
            return 'with these rules in place, a request that a rule loaded before them denies gets through. '
                .'They would switch ModSecurity off or to detection-only, or remove the rules before them, for every site.';
        }
        foreach ([1, 2, 3] as $phase) {
            if ($answers["phase{$phase}"] === '204') {
                return "with these rules in place, a request that a phase {$phase} rule loaded after them denies gets through. "
                    ."They would stop the later rules in phase {$phase}, for every site "
                    .'(an allow, a skip or skipAfter, or a ctl that matches every request).';
            }
        }
        if ($answers['body'] === '204') {
            return 'with these rules in place, a request body that a rule loaded after them denies gets through. '
                .'They would stop ModSecurity reading request bodies, for every site (a ctl that matches every request).';
        }
        if ($answers['plain'] !== '204') {
            return "with these rules in place, an ordinary request with nothing in it to block is answered {$answers['plain']} "
                .'instead of reaching the site. These rules would deny ordinary requests, on every site.';
        }

        return null;
    }

    /**
     * The line the test refused on, or null when it passed. nginx prints its refusal twice, once through error_log
     * with a timestamp.
     */
    public static function configTestProblem(?int $exitCode, string $output): ?string
    {
        if ($exitCode === 0) {
            return null;
        }
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

    /** @return ?list<string> */
    public static function liveConfigTestCommand(string $webserver): ?array
    {
        return match ($webserver) {
            'nginx', 'nginx-proxy' => ['nginx', '-t'],
            'apache' => ['apachectl', '-t'],
            default => null,
        };
    }

    /**
     * A parse proves only that the rules load. Whatever they do to the engine, however spelt, shows when the scratch
     * config answers the check requests.
     *
     * @return ?string the problem, or null when the rules parse (and, on nginx, pass the check)
     */
    protected function configTest(string $webserver, string $testDir): ?string
    {
        if (! self::canTestOn($webserver)) {
            return "custom rules cannot be checked on the {$webserver} webserver, so they are not applied";
        }
        $this->system->filesystem()->writeFileReplacingPath(
            $testDir.($webserver === 'apache' ? '/httpd.conf' : '/nginx.conf'),
            self::configTestConfig($webserver, $testDir)
        );
        if ($webserver === 'apache') {
            return $this->testInWebserver($this->services()->modsecurityApacheCheck($testDir));
        }

        $this->system->filesystem()->writeFileReplacingPath($testDir.'/check.conf', self::checkRules($testDir));
        $process = $this->runInWebserver(
            $this->services()->modsecurityNginxCheck($testDir)
        );
        $output = $process->getErrorOutput()."\n".$process->getOutput();

        return self::configTestProblem($process->getExitCode(), $output) ?? self::checkProblem($output);
    }

    /**
     * A refusal outside the rules names other sites' files, so that goes to the log only.
     *
     * @return ?string the problem, or null when the live config, rules included, passes
     */
    protected function liveConfigTest(string $webserver): ?string
    {
        if (self::liveConfigTestCommand($webserver) === null) {
            return "custom rules cannot be checked on the {$webserver} webserver, so they are not applied";
        }
        $services = $this->services();
        $problem = $this->testInWebserver($webserver === 'apache' ? $services->apacheConfigTest() : $services->nginxConfigTest());
        if ($problem !== null && ! str_contains($problem, 'Rules error')) {
            Log::warning('Webserver config test failed with the new ModSecurity rules in place', ['verdict' => $problem]);

            return 'the webserver configuration does not pass its own test; what it said is in the engine log';
        }

        return $problem;
    }

    /** @param  list<string>  $argv */
    private function testInWebserver(array $argv): ?string
    {
        $process = $this->runInWebserver($argv);

        return self::configTestProblem(
            $process->getExitCode(),
            $process->getErrorOutput()."\n".$process->getOutput()
        );
    }

    /** @param  list<string>  $argv */
    private function runInWebserver(array $argv): Process
    {
        return $this->system->runProcessOnHost($argv, [], self::CONFIG_TEST_TIMEOUT);
    }

    private function services(): RootEngineServices
    {
        return Privileged::engineServices($this->system->composeFilePath());
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
        $path = $this->system->engineDirPath().'/config/modsecurity/rulesets/'.self::CUSTOM_RULESET
            .'/rules/'.self::CUSTOM_RULES_FILE;

        return ! $this->system->filesystem()->fileExists($path)
            && $this->system->filesystem()->fileExists($path.'.disabled')
            ? $path.'.disabled'
            : $path;
    }

    /**
     * @param  array<string>  $cmd
     */
    private function runProcessOrFail(array $cmd): void
    {
        $process = $this->system->runProcess($cmd);
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException($message !== '' ? $message : 'ModSecurity config file toggle failed');
        }
    }
}
