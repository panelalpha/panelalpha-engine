<?php

namespace App\System\Services;

use App\Models\Setting;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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

    /**
     * Rules only: no Include, no log or data paths, no remote rule sources. Each
     * with the arguments it takes; libmodsecurity ends the directive after them
     * and reads what follows as another one. null: the rest of the line.
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
     * Every directive libmodsecurity 3 recognises. Its lexer takes one wherever a
     * directive may start, with no separator after it (`SecRuleEngineOff` is
     * SecRuleEngine Off), so these are matched as prefixes, and quotes do not hide
     * them: the static checks do not read quotes exactly as libmodsecurity does.
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
     * The operators libmodsecurity 3's rule parser knows, matched case-insensitively.
     * It does not refuse any other `@name`: it reads the whole operator as a regular
     * expression, so a misspelt one parses and then matches nothing (negated, everything).
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
     * The scratch check: a rule loaded before the candidate, and after it one
     * rule for each phase a rule can stop the later ones in (phase 4 does not
     * run, response bodies are not read) and one on a request body. Each denies
     * one request. Ids clear of CRS, the engine's own rules and the custom range;
     * no real rule answers 418, so that answer proves the check's rule denied it.
     * Then a plain browser GET for /, which nothing should deny.
     */
    private const CHECK_PATH = '/panelalpha-modsecurity-check/';
    private const CHECK_STATUS = '418';
    private const CHECK_BODY_ARG = 'panelalpha_check';
    private const CHECK_REQUESTS = ['first', 'phase1', 'phase2', 'phase3', 'body', 'plain'];

    /**
     * Runs in sites-http: the parse test, then the scratch config as a server of
     * its own on a socket in the test dir, asked once for each check request. The
     * trap and timeout stop that server on every path out.
     */
    private const CHECK_SCRIPT = <<<'SH'
        T=$1
        nginx -t -q -c "$T/nginx.conf" || exit
        timeout -k 1 20 nginx -c "$T/nginx.conf" &
        pid=$!
        trap 'kill $pid 2>/dev/null; wait $pid 2>/dev/null' EXIT
        trap 'exit 1' HUP INT TERM
        i=0
        until [ -S "$T/check.sock" ]; do
            kill -0 $pid 2>/dev/null || exit 1
            i=$((i + 1)); [ $i -lt 250 ] || { echo 'the check server did not start' >&2; exit 1; }
            sleep 0.02
        done
        ask() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 --unix-socket "$T/check.sock" "$@"; }
        for p in first phase1 phase2 phase3; do
            echo "modsecurity-check $p $(ask "http://localhost$2$p")"
        done
        echo "modsecurity-check body $(ask --data "$3=1" "http://localhost$2body")"
        plain() { ask -A 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0' \
            -H 'Accept: text/html,*/*;q=0.8' -H 'Accept-Language: en' http://localhost/; }
        echo "modsecurity-check plain $(plain)"
        SH;

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

        $rulesets = $config['enabled_rulesets'];
        // In the place the live config loads them: what they do to CRS depends on
        // whether they come before it. Enabled later, a rule set is appended.
        if ($customRules !== null && !in_array(self::CUSTOM_RULESET, $rulesets, true)) {
            $rulesets[] = self::CUSTOM_RULESET;
        }
        $includeFiles = [];
        foreach ($rulesets as $ruleset) {
            if ($customRules !== null && $ruleset === self::CUSTOM_RULESET) {
                $includeFiles[] = $customRules;
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
     * has parsed them together with every enabled rule set (on nginx, and then
     * run them, see configTest()), and, when they are loaded, has passed its
     * live config with them in place. A rule either test refuses does not stay
     * in the live file, so a reload cannot fail on it.
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
            // On, so the check sees whether the candidate turns it off.
            $this->system->filesystem()->writeFileReplacingPath($testDir . '/main.conf', $this->renderMainConf('On', $candidate));

            $problem = $this->configTest($webserver, $testDir);
        } finally {
            $this->system->runProcess(['sudo', 'rm', '-rf', $testDir]);
        }
        if ($problem !== null) {
            throw new \InvalidArgumentException('The webserver refused the rules: ' . $problem);
        }

        $live = $this->customRulesPath();
        $previous = $this->system->filesystem()->fileExists($live)
            ? $this->system->filesystem()->fileGetContents($live)
            : null;
        $this->system->filesystem()->writeFileReplacingPath($live, $rules);

        if (!$this->customRulesEnabled() || Setting::getModsecConfig()['mode'] === 'off') {
            return;
        }

        // A data file named relative to the rules resolves next to the scratch
        // copy, not here. A live config that fails would keep the reload from
        // applying and a restart from starting, so the old rules go back.
        try {
            $problem = $this->liveConfigTest($webserver);
        } catch (\Throwable $e) {
            // A test that timed out or could not run has not passed either.
            $this->restoreCustomRules($live, $previous);
            throw $e;
        }
        if ($problem !== null) {
            $this->restoreCustomRules($live, $previous);
            throw new \InvalidArgumentException('The webserver refused the rules in place, so the previous rules stay live: ' . $problem);
        }

        $this->restartWebserver();
    }

    private function restoreCustomRules(string $live, ?string $previous): void
    {
        if ($previous === null) {
            // A silent rm failure would leave the refused rules live, so it throws.
            $this->runProcessOrFail(['sudo', 'rm', '-f', $live]);
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
            return 'The rules are larger than ' . (self::CUSTOM_RULES_MAX_BYTES / 1024) . ' KB.';
        }

        // Anywhere, even in a comment or a message: the mode is the setting for
        // every site, and ctl:ruleEngine in a rule that matches only some requests
        // switches off every rule after it for those.
        foreach ([$rules, preg_replace('/\\\\\R[ \t]*/', '', $rules)] as $text) {
            if (stripos((string) $text, 'ruleengine') !== false) {
                return 'SecRuleEngine and ctl:ruleEngine are not accepted in custom rules, in any spelling, not even in a comment '
                    . 'or a message. The engine\'s mode is the ModSecurity setting for every site; a rule that changes it '
                    . 'switches protection off or to detection-only. To exempt requests from a rule, use ctl:ruleRemoveById.';
            }
        }

        $logical = self::logicalLines($rules);
        if (is_string($logical)) {
            return $logical;
        }
        $keywords = self::directiveKeywordPattern();
        $ids = [];
        $markers = [];
        $skipAfters = [];
        foreach ($logical as $at => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $tokens = self::tokenize($line);
            if ($tokens === []) {
                continue;
            }

            // libmodsecurity ends a directive at the close of its actions, a marker
            // name or a bare backslash, and reads another one straight after it, glued
            // on or not. A directive name anywhere but the start of a line is refused.
            preg_match_all($keywords, $line, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $offset]) {
                if ($offset !== 0) {
                    return 'A line may hold only one directive, at its start. Put each SecRule, SecAction or SecMarker on '
                        . 'its own line (a chained rule is several lines, each a SecRule). A directive name may not appear '
                        . 'anywhere else on the line, not even inside quotes: "' . Str::limit($line, 80) . '"';
                }
            }

            if ($tokens[0]['quoted']) {
                return 'A directive must not be quoted.';
            }
            $directive = strtolower($tokens[0]['value']);
            if (!array_key_exists($directive, self::CUSTOM_DIRECTIVES)) {
                return "Only rule directives are accepted (SecRule, SecAction, SecMarker, SecRuleRemoveBy*, SecRuleUpdate*By*), not {$directive}.";
            }
            if (!self::argumentsFit($directive, array_slice($tokens, 1))) {
                return 'There is more on this line than its directive takes, and ModSecurity would read the rest as another '
                    . 'directive. Only a # comment may follow the last argument: "' . Str::limit($line, 80) . '"';
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
            if ($directive === 'secrule') {
                $problem = self::operatorProblem($tokens);
                if ($problem !== null) {
                    return $problem;
                }
            }

            // skip:N counts the rules ModSecurity runs next, in every rule set and on
            // into later phases, so where it lands depends on what is loaded around these.
            if (preg_match('/(?<![a-z0-9_])skip\s*:/i', $line)) {
                return 'skip is not accepted in custom rules: it counts the rules ModSecurity runs next, in every rule set '
                    . 'and into later phases. Use skipAfter with a SecMarker after the rules to skip.';
            }
            if ($directive === 'secmarker') {
                // libmodsecurity drops single quotes around the name as well.
                $markers[preg_replace("/\\A'(.*)'\\z/s", '$1', $tokens[1]['value'] ?? '')] = $at;
            }
            if (preg_match_all('/(?<![a-z0-9_])skipafter\s*:\s*\'?([^\'",\s]*)/i', $line, $m)) {
                if (!in_array($directive, ['secrule', 'secaction'], true)) {
                    return 'skipAfter is accepted only in a SecRule or SecAction, before the SecMarker it names.';
                }
                foreach ($m[1] as $name) {
                    $skipAfters[] = [$name, $at];
                }
            }
        }

        // A marker that is missing, misspelt or before the rule makes ModSecurity skip
        // every rule it reaches until that name: in every rule set and later phases.
        foreach ($skipAfters as [$name, $at]) {
            if (($markers[$name] ?? -1) <= $at) {
                return "skipAfter:{$name} names no SecMarker after it in these rules (names are case-sensitive). ModSecurity "
                    . 'would skip rules outside these, in every rule set and in later phases, for every request it matches. '
                    . "Put SecMarker {$name} after the rules it should skip.";
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
                // A commented-out SecRule/SecAction ending in \ is carried onto the
                // following lines by libmodsecurity, swallowing them (blank lines and
                // further comments included) as comment. A real directive among them
                // would silently never load, so it is refused.
                if (preg_match('/^[ \t]*#[ \t]*sec(?:rule|action)[^\\\\].*\\\\[ \t]*$/i', $physical[$n])) {
                    for ($m = $n + 1; $m < $count; $m++) {
                        $swallowed = trim($physical[$m]);
                        if ($swallowed === '') {
                            continue;
                        }
                        if ($swallowed[0] !== '#') {
                            return 'Line ' . ($n + 1) . ' is a commented-out SecRule/SecAction that ends in \\. '
                                . 'ModSecurity reads line ' . ($m + 1) . ' as part of the comment, so that rule would never load. '
                                . 'Comment that line too, or remove the backslash.';
                        }
                        if (!str_ends_with(rtrim($physical[$m]), '\\')) {
                            break;
                        }
                    }
                    $n = $m;
                }
                // An ordinary comment, even one ending in \, is left out: libmodsecurity
                // ends it at the newline, so the next line is a directive of its own.
                continue;
            }
            // A backslash at the end of a directive's line continues it on the next
            // one, but not once the directive is complete: libmodsecurity then reads
            // the next line as a directive of its own, and joining would hide it.
            $current = $physical[$n];
            while ($n + 1 < $count && str_ends_with(rtrim($current), '\\')) {
                $head = substr(rtrim($current), 0, -1);
                if (self::isComplete($head)) {
                    return 'Line ' . ($n + 1) . ' ends in \\ after a complete directive. ModSecurity does not carry a '
                        . 'finished directive onto the next line; it reads that line as a directive of its own. '
                        . 'Remove the backslash.';
                }
                $current = $head . ' ' . $physical[$n + 1];
                $n++;
            }
            $logical[] = $current;
        }

        return $logical;
    }

    /** A directive with all the arguments it takes, the last one closed. */
    private static function isComplete(string $text): bool
    {
        $tokens = self::tokenize(trim($text));
        if ($tokens === [] || $tokens[0]['quoted'] || !$tokens[count($tokens) - 1]['closed']) {
            return false;
        }
        $directive = strtolower($tokens[0]['value']);
        if (!array_key_exists($directive, self::CUSTOM_DIRECTIVES)) {
            return false;
        }
        $takes = self::CUSTOM_DIRECTIVES[$directive];

        return count($tokens) - 1 >= ($takes ?? 1);
    }

    /**
     * Whether the arguments are no more than the directive takes, a trailing #
     * comment aside. A directive taking the rest of the line takes one quoted
     * value or bare words.
     *
     * @param list<array{value: string, quoted: bool, closed: bool}> $arguments
     */
    private static function argumentsFit(string $directive, array $arguments): bool
    {
        $taken = [];
        foreach ($arguments as $argument) {
            if (!$argument['quoted'] && str_starts_with($argument['value'], '#')) {
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

    /** Any directive name, as libmodsecurity's lexer would take it where a directive may start. */
    private static function directiveKeywordPattern(): string
    {
        $words = self::DIRECTIVE_KEYWORDS;
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        // Include alone needs a blank after it; the Sec* names take nothing.
        $words = array_map(fn (string $w): string => $w === 'include' ? 'include(?=[ \t])' : $w, $words);

        return '/(?<![A-Za-z0-9_])(?:' . implode('|', $words) . ')/i';
    }

    /**
     * A directive's arguments in order, each marked quoted or bare. A quoted
     * argument spans one "...", with \" an escaped quote, and is not closed when
     * the line ends inside it; a bare one runs to the next space, keeping any \"
     * so an escaped quote inside a variable does not open a string.
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
                $tokens[] = ['value' => $value, 'quoted' => true, 'closed' => $closed];
                continue;
            }
            $value = '';
            while ($i < $len && $line[$i] !== ' ' && $line[$i] !== "\t" && $line[$i] !== '"') {
                if ($line[$i] === '\\' && $i + 1 < $len) {
                    $value .= $line[$i] . $line[$i + 1];
                    $i += 2;
                    continue;
                }
                $value .= $line[$i];
                $i++;
            }
            if ($value !== '') {
                $tokens[] = ['value' => $value, 'quoted' => false, 'closed' => true];
            }
        }

        return $tokens;
    }

    /**
     * Why a SecRule's operator would not be read as the operator it names, or null.
     *
     * @param list<array{value: string, quoted: bool, closed: bool}> $tokens
     */
    private static function operatorProblem(array $tokens): ?string
    {
        // SecRule VARIABLES OPERATOR [ACTIONS]: the operator is the third argument.
        if (!isset($tokens[2])) {
            return null;
        }
        $operator = $tokens[2]['value'];
        if (!preg_match('/\A\s*!?\s*@/', $operator)) {
            // No operator named: libmodsecurity reads it as an @rx, which is fine.
            return null;
        }
        $shown = Str::limit($operator, 100);
        // libmodsecurity takes an operator only right after the quote or the `!`.
        if (!preg_match('/\A!?@(\w*)([\s\S]*)\z/', $operator, $m)) {
            return "The operator \"{$shown}\" has a space before the @, so ModSecurity would read it as a regular expression, "
                . 'not as an operator. To match a literal @, use @rx.';
        }
        $name = strtolower($m[1]);
        if (!in_array($name, self::OPERATORS, true)) {
            return "Unknown operator @{$m[1]}. ModSecurity would read \"{$shown}\" as a regular expression, "
                . 'so the rule would not do what it says. To match a literal @, use @rx.';
        }
        // These operators take no argument: quoted with anything but spaces after the
        // name, libmodsecurity reads the whole text as a regex instead.
        if ($tokens[2]['quoted'] && in_array($name, self::NO_ARG_OPERATORS, true) && trim($m[2]) !== '') {
            return "The operator @{$m[1]} takes no argument. ModSecurity would read \"{$shown}\" as a regular expression, "
                . 'so the rule would not do what it says.';
        }

        return null;
    }

    /**
     * The webserver's config test over a minimal config that loads only
     * ModSecurity and $testDir/main.conf, run inside sites-http so it parses
     * with the same module and paths as the live server. On nginx it then runs
     * that config, see configTest().
     */
    public static function configTestCommand(string $webserver, string $testDir): ?string
    {
        return match ($webserver) {
            'nginx', 'nginx-proxy' => 'sh -c ' . escapeshellarg(self::CHECK_SCRIPT)
                . ' sh ' . escapeshellarg($testDir) . ' ' . escapeshellarg(self::CHECK_PATH) . ' ' . escapeshellarg(self::CHECK_BODY_ARG),
            'apache' => 'httpd -t -f ' . escapeshellarg($testDir . '/httpd.conf'),
            default => null,
        };
    }

    public static function configTestConfig(string $webserver, string $testDir): string
    {
        if ($webserver === 'apache') {
            $rules = $testDir . '/main.conf';

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

        // Started for a second next to the live server: every file it writes
        // (pid, lock, temp dirs, its socket) stays in the test dir. A socket path
        // holds 107 bytes; under the engine dir it takes 92.
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
            // try_files answers after ModSecurity's phases; `return` would answer before phase 2.
            "            root {$testDir}/www;",
            '            try_files $uri =204;',
            '        }',
            '    }',
            '}',
            '',
        ]);
    }

    /**
     * The candidate's main.conf between the two check rules. Anything it does to
     * the engine's mode is in force here as it would be live.
     */
    public static function checkRules(string $testDir): string
    {
        $deny = fn (int $id, int $phase, string $variable, string $operator): string => "SecRule {$variable} \"{$operator}\" "
            . "\"id:{$id},phase:{$phase},deny,status:" . self::CHECK_STATUS . ',nolog,noauditlog"';
        $path = fn (string $name): string => '@streq ' . self::CHECK_PATH . $name;

        return implode("\n", [
            $deny(1099990, 1, 'REQUEST_URI', $path('first')),
            "Include {$testDir}/main.conf",
            // Keeps the check's own requests out of the live audit log.
            'SecAuditEngine Off',
            // A rule that stops the later ones does so for one phase or from it on.
            $deny(1099991, 1, 'REQUEST_URI', $path('phase1')),
            $deny(1099992, 2, 'REQUEST_URI', $path('phase2')),
            $deny(1099993, 3, 'REQUEST_URI', $path('phase3')),
            $deny(1099994, 2, 'ARGS_POST:' . self::CHECK_BODY_ARG, '@streq 1'),
            '',
        ]);
    }

    /**
     * Why the check's answers say the rules switch protection off or deny every
     * request, or null. The first rule, loaded before the candidate, must still
     * deny; a request a rule after it denies must not get through to the
     * server's own answer (204); the plain request must.
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
        if ($answers['first'] !== self::CHECK_STATUS) {
            return 'with these rules in place, a request that a rule loaded before them denies gets through. '
                . 'They would switch ModSecurity off or to detection-only, or remove the rules before them, for every site.';
        }
        foreach ([1, 2, 3] as $phase) {
            if ($answers["phase{$phase}"] === '204') {
                return "with these rules in place, a request that a phase {$phase} rule loaded after them denies gets through. "
                    . "They would stop the later rules in phase {$phase}, for every site "
                    . '(an allow, a skip or skipAfter, or a ctl that matches every request).';
            }
        }
        if ($answers['body'] === '204') {
            return 'with these rules in place, a request body that a rule loaded after them denies gets through. '
                . 'They would stop ModSecurity reading request bodies, for every site (a ctl that matches every request).';
        }
        // A set that denies every request passes the checks above, and takes every site down.
        if ($answers['plain'] !== '204') {
            return "with these rules in place, an ordinary request with nothing in it to block is answered {$answers['plain']} "
                . 'instead of reaching the site. These rules would deny ordinary requests, on every site.';
        }

        return null;
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

    /** The webserver's test of the configuration it actually runs. */
    public static function liveConfigTestCommand(string $webserver): ?string
    {
        return match ($webserver) {
            'nginx', 'nginx-proxy' => 'nginx -t',
            'apache' => 'apachectl -t',
            default => null,
        };
    }

    /**
     * @return ?string the problem, or null when the rules parse (and, on nginx, pass the check)
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
        if ($webserver === 'apache') {
            return $this->testInWebserver($command);
        }

        // A parse proves only that the rules load. Whatever they do to the engine,
        // however spelt, shows when the scratch config answers the check requests.
        $this->system->filesystem()->writeFileReplacingPath($testDir . '/check.conf', self::checkRules($testDir));
        $process = $this->runInWebserver($command);
        $output = $process->getErrorOutput() . "\n" . $process->getOutput();

        return self::configTestProblem($process->getExitCode(), $output) ?? self::checkProblem($output);
    }

    /**
     * @return ?string the problem, or null when the live config, rules included, passes
     */
    protected function liveConfigTest(string $webserver): ?string
    {
        $command = self::liveConfigTestCommand($webserver);
        if ($command === null) {
            return "custom rules cannot be checked on the {$webserver} webserver, so they are not applied";
        }
        $problem = $this->testInWebserver($command);
        // A refusal outside the rules names other sites' files; that goes to the log only.
        if ($problem !== null && !str_contains($problem, 'Rules error')) {
            Log::warning('Webserver config test failed with the new custom ModSecurity rules in place', ['verdict' => $problem]);

            return 'the webserver configuration does not pass its own test; what it said is in the engine log';
        }

        return $problem;
    }

    private function testInWebserver(string $command): ?string
    {
        $process = $this->runInWebserver($command);

        return self::configTestProblem(
            $process->getExitCode(),
            $process->getErrorOutput() . "\n" . $process->getOutput()
        );
    }

    private function runInWebserver(string $command): Process
    {
        return $this->system->runProcessOnHost(
            'sudo docker compose -f ' . escapeshellarg($this->system->composeFilePath())
                . ' exec -T sites-http ' . $command,
            [],
            self::CONFIG_TEST_TIMEOUT
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
