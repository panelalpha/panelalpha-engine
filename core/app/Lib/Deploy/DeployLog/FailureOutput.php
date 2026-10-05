<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * The part of a failed build's output that says why: the first announcing line
 * in the last attempt the build made, followed by what it said next.
 *
 * Handing the whole stderr to {@see DeployFailureExplainer}, whose rules match
 * anywhere in it, let the first recognisable line win: galette, glpi and
 * octobercms were each reported by an `npm warn deprecated` line while the
 * build had died elsewhere. This only decides which text the explainer is given.
 */
final class FailureOutput
{
    /**
     * How far back to look for the failure. A build stage's own output is tens
     * of lines; beyond that is a previous stage's, which did not fail.
     */
    private const WINDOW = 80;

    /** How much of the failing region to keep. */
    private const CONTEXT = 12;

    /** How much to keep when nothing announced itself. */
    private const FALLBACK = 6;

    /**
     * How much of a failed build step's own output to keep. publify's
     * `Unable to find libclang` sat 46 lines above `#11 ERROR:`, under
     * Bundler's backtrace.
     */
    private const STEP_LINES = 60;

    /**
     * A build step its memory limit stopped. The compile errors printed above
     * it are symptoms, and can be further up than CONTEXT reaches.
     */
    private const MEMORY_EXHAUSTED = '/(?:ResourceExhausted:|did not complete successfully:)[^\n]*cannot allocate memory/';

    /** BuildKit's `#<step> <seconds> ` in front of a step's output line. */
    private const STEP_PREFIX = '/^#\d+ \d+(?:\.\d+)? /';

    /**
     * A line that says "this is where it died", anchored so that the same
     * words inside prose cannot match.
     */
    private const CAUSE = [
        '/^#\d+ ERROR:/',                       // BuildKit's own error line
        // tar restoring an owner the account's id range cannot hold; Flutter
        // prints its network advice after it.
        '/Cannot change ownership to uid \d+, gid \d+: Invalid argument/',
        '/^failed to solve:/',                    // ...and the summary that names it
        '/^Error response from daemon:/',         // the daemon refusing to run a container
        // `runc run failed:` is a RUN step that could not start (minthcm, engine#111).
        '/^runc (?:create|run) failed:/',
        // apt's own error lines (`E: Failed to fetch ... 404`), above the step's `#N ERROR:`.
        // The account's apt-lists denial is no finding (see NOISE).
        '/^E: (?!List directory \/var\/lib\/apt\/lists\/partial is missing)/',
        '/^npm error (?!npm error)/',            // npm's own error block
        '/^npm ERR!/',
        '/^gyp ERR!/',
        '/^\s*error Command failed/i',           // yarn
        '/^ERR!/',                               // pnpm
        '/^\s*error:/i',                         // python, shell
        '/^(?:ERROR|FATAL)(?:\s|:)/',            // webpack, gradle
        // Maven prefixes every line with a bracket, so a blanket /^\[ERROR\]/
        // would also match its per-goal detail; the goal line names the plugin
        // and the module, and is the one a reader needs.
        '/^\[ERROR\]\s+Failed to execute goal/',
        '/^\[ERROR\]\s+(?:BUILD FAILURE|Failed to read|Non-resolvable|Internal error)/',
        // Go's compiler: nothing here matched a Go failure, so the report led
        // with whatever came first, e.g. "Loaded base image golang:1.25-alpine
        // from host cache". `.go:` is unambiguous.
        '/^[\w.\/@-]+\.go:\d+:\d+:/',           // internal/thumb/vips_init.go:18:32: undefined: ...
        '/^package [\w.\/-]+: build constraints exclude all Go files/',
        // Go wraps a long import chain, so the reason lands on the last
        // indented `imports` line, not the `package` line above it.
        '/^\s+imports [\w.\/-]+\S*: (?:build constraints|no required module|cannot find)/',
        // A repo with no buildable package at all -- lura is a framework.
        '/^no Go files in \//',
        // The toolchain refusing go.mod's version (GOTOOLCHAIN=local in the official image).
        '/^go: go\.mod requires go >= /',
        '/^error: cannot find module providing package /',
        '/^FAILURE: Build failed/',              // Gradle's own banner
        '/^\* What went wrong:/',                // ...and the section naming the task
        '/^failed to pull /',                    // compose: an image it cannot fetch
        '/^unable to get image /',               // compose: an image reference it refuses
        '/^service "[^"]+" has neither /',       // compose: an invalid project
        '/^dependency failed to start:/',         // compose: a depends_on that never came up
        '/^service "[^"]+" didn\'t complete successfully/',
        '/^gyp: /',
        '/^\s*PHP Fatal error:/i',
        '/^Traceback \(most recent call last\)/',
        // Ruby's require of a gem the bundle does not have; its backtrace follows.
        '/^LoadError: cannot load such file -- /',
        '/^fatal:/',                             // git
        '/^(?:Memory cgroup )?[Oo]ut of memory\b/',
        '/^Killed\b/',
    ];

    /**
     * Benign lines that must never become the headline. npm's `warn` is
     * advisory -- it installs the package anyway -- and BuildKit's progress and
     * webpack's per-module lines are the bulk of any build. npm's error object
     * is not here: `code:` in it is often the useful part.
     */
    private const NOISE = [
        '/^npm warn\b/i',
        '/^npm notice\b/i',
        '/^warning\s/i',
        // The JVM prints this on stderr for every process it starts, and the
        // engine sets JAVA_TOOL_OPTIONS itself when it sizes a build heap.
        '/^Picked up (?:JAVA_TOOL_OPTIONS|_JAVA_OPTIONS)/',
        // Docker compose logs in logfmt, so its level is a field rather than a
        // prefix: `time="..." level=warning msg="..."`. Those are notices about
        // its own interpolation (`service "foodsoft" has neither an image nor a
        // build context specified` is the line after). `level=error` and
        // `level=fatal` are kept.
        '/^time="[^"]*" level=(?:debug|info|warning)\b/',
        // BuildKit's progress and layer headers. `#N ERROR` is NOT here: it is
        // BuildKit's own error line and usually the only one naming the cause.
        '/^#\d+ (?:DONE|CACHED)\b/',
        '/^#\d+ \[[^\]]*\]\s/',                  // BuildKit layer headers
        '/^<s> /',                               // webpack progress rewrites
        '/^\[\d+\.\d+s\]\s*$/',                  // bare timings
        // Maven's `[INFO]` chatter is never the failure. Only the bracket is
        // dropped, so `[ERROR] Failed to execute goal ...` still leads.
        '/^\[INFO\]/',
        // The `maven:` image's entrypoint tries to create /root before Maven
        // runs; the engine compiles Java on the host as the account, and the
        // real failure is thousands of lines later. A `mkdir` naming anything
        // else is still a finding.
        // coreutils quotes it with ‘’, three bytes each, which a bare `.`
        // never matched: the real line was never dropped (#86).
        '/^mkdir: cannot create directory (?:\'|"|‘)\/root(?:\'|"|’):/',
        // `apk add` runs as the account, not root, so this is ignored -- a Go
        // host compile wraps it in `|| true`. `ERROR:` on another subject is
        // still a finding.
        '/^ERROR: Unable to open log: Permission denied$/',
        // `docker compose up`'s progress: an image, network, volume or
        // container changing state, and the per-layer pull lines. On a stack
        // pulling several images this is hundreds of lines and it was the
        // whole reported "reason" for rero-ils (#112). A line saying `Error`
        // matches neither and is kept.
        '/^\s*(?:Image|Network|Volume|Container)\s+\S+\s+(?:Pulling|Pulled|Building|Built|Creating|Created'
            . '|Starting|Started|Waiting|Healthy|Running|Recreate|Recreated|Stopping|Stopped|Removing|Removed'
            . '|Skipped)(?:\s+[\d.]+s)?\s*$/',
        '/^\s*[0-9a-f]{12}\s+(?:Pulling fs layer|Waiting|Downloading|Download complete|Verifying Checksum'
            . '|Extracting|Pull complete|Already exists)\b/',
        // `docker run`'s own pull of an image it does not have yet, which a
        // host build prints on stderr ahead of anything the build says.
        '/^Unable to find image \'[^\']+\' locally$/',
        '/^[\w.-]+: Pulling from [\w.\/:-]+$/',
        '/^[0-9a-f]{12}: (?:Pulling fs layer|Waiting|Downloading|Download complete|Verifying Checksum'
            . '|Extracting|Pull complete|Already exists)\b/',
        '/^Digest: sha256:[0-9a-f]{64}$/',
        '/^Status: (?:Downloaded newer image|Image is up to date) for \S+$/',
        // The same for `apt-get update`, best effort in the Rust host compile
        // (`|| true`): the account cannot write the apt lists (#86).
        '/^E: List directory \/var\/lib\/apt\/lists\/partial is missing\. - Acquire \(13: Permission denied\)$/',
        // A Makefile's `git describe` in a build context with no .git (ntfy). Real
        // git failures (`fatal: repository ... not found`) still lead.
        '/^(?:#\d+ \d+(?:\.\d+)? )?fatal: not a git repository\b/',
        '/^\s*$/',
    ];

    public static function select(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $built = self::builtTags($lines);
        $lines = array_values(array_filter(
            $lines,
            static fn (string $l): bool => !self::isNoise($l) && !self::isPullOfBuiltTag($l, $built)
        ));
        if ($lines === []) {
            return '';
        }

        $window = array_slice($lines, -self::WINDOW);
        foreach ($window as $i => $line) {
            if (preg_match(self::MEMORY_EXHAUSTED, $line) === 1) {
                return trim(implode("\n", array_slice($window, $i, self::CONTEXT)));
            }
        }
        $offset = count($lines) - count($window);
        foreach ($window as $i => $line) {
            // A build step's own output carries BuildKit's `#13 249.2 ` prefix.
            $bare = (string) preg_replace(self::STEP_PREFIX, '', $line);
            foreach (self::CAUSE as $pattern) {
                if (preg_match($pattern, $line) === 1 || preg_match($pattern, $bare) === 1) {
                    $start = self::npmBlockStart($lines, $offset + $i);

                    return self::stepCause($lines, $offset + $i)
                        ?? trim(implode("\n", array_slice($lines, $start, self::CONTEXT)));
                }
            }
        }

        // Nothing announced itself, so there is no failing line to centre on.
        // Composer's `Your requirements could not be resolved` / `Problem 1`
        // matches no CAUSE pattern and is followed by boilerplate .ini advice,
        // so the whole window is returned -- it is already bounded to WINDOW
        // non-noise lines, and keeps whatever the explainer can recognise.
        return trim(implode("\n", $window));
    }

    /**
     * The output without its progress and advisory lines, for a failure no
     * explainer rule matched. A host build's stderr starts with docker's image
     * pull, which was reported as the reason. Nothing else is dropped: the
     * text may be a sentence the engine already made.
     */
    public static function withoutNoise(string $output): string
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        $kept = array_filter($lines, static fn (string $l): bool => !self::isNoise($l));

        return $kept === [] ? trim($output) : trim(implode("\n", $kept));
    }

    /**
     * What the failed BuildKit step printed, from `docker compose up --build`'s
     * stdout: the `#N` lines of the first step that reported `#N ERROR:`.
     *
     * Compose writes the build progress to stdout and only the `failed to
     * solve` summary to stderr, so the explainer saw "exit code: 101" and never
     * the `error: could not compile` or `pattern ...: no matching files found`
     * above it.
     */
    public static function failedBuildStep(string $stdout): string
    {
        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        $step = null;
        foreach ($lines as $line) {
            if (preg_match('/^#(\d+) ERROR:/', $line, $m) === 1) {
                $step = $m[1];
                break;
            }
        }
        if ($step === null) {
            return '';
        }

        $own = array_values(array_filter(
            $lines,
            static fn (string $l): bool => str_starts_with($l, '#' . $step . ' ')
        ));

        return trim(implode("\n", array_slice($own, -self::STEP_LINES)));
    }

    /**
     * The text a failed build process is reported by: its stderr, unless that
     * is nothing but noise. The maven image's `mkdir: cannot create directory
     * '/root'` and the JVM's JAVA_TOOL_OPTIONS banner go to stderr while Maven
     * prints `[ERROR] ... release version 25 not supported` to stdout, and the
     * banner was reported as the reason (#86).
     */
    public static function fromStreams(string $stderr, string $stdout): string
    {
        if (self::select($stderr) === '') {
            $region = self::select($stdout);
            if ($region !== '') {
                return $region;
            }
        }

        return $stderr !== '' ? $stderr : $stdout;
    }

    /**
     * When BuildKit's `#N ERROR:` is the first line that announced itself, the
     * cause is in what step N printed above it, which no CAUSE pattern knows:
     * yum's `Could not resolve host: mirrorlist.centos.org` (flexisip), a
     * bindgen panic under a Bundler backtrace (publify). The region starting
     * at `#N ERROR:` only reaches the generic exit code, so it is centred on
     * the step's own line an explainer rule recognises instead. Null when
     * none does: the old region stands.
     *
     * @param list<string> $lines
     */
    private static function stepCause(array $lines, int $at): ?string
    {
        if (preg_match('/^#(\d+) ERROR:/', $lines[$at], $m) !== 1) {
            return null;
        }
        $own = array_values(array_filter(
            array_slice($lines, 0, $at),
            static fn (string $l): bool => str_starts_with($l, '#' . $m[1] . ' ')
        ));
        $rule = DeployFailureExplainer::match(implode("\n", $own))['rule'] ?? null;
        if ($rule === null) {
            return null;
        }

        // The shortest tail of the step that still reads as that rule starts at its line.
        for ($k = count($own) - 1; $k >= 0; $k--) {
            $tail = array_slice($own, $k);
            if ((DeployFailureExplainer::match(implode("\n", $tail))['rule'] ?? null) !== $rule) {
                continue;
            }
            $region = implode("\n", array_slice($tail, 0, self::CONTEXT));

            return trim((DeployFailureExplainer::match($region)['rule'] ?? null) === $rule ? $region : implode("\n", $tail));
        }

        return null;
    }

    /**
     * Where the `npm error` block holding line $at begins. npm 11 prints a
     * command's whole usage after EUSAGE, longer than WINDOW, so the window
     * opened mid-block and `npm error code` and its reason were cut off.
     *
     * @param list<string> $lines
     */
    private static function npmBlockStart(array $lines, int $at): int
    {
        $isNpmError = static fn (string $l): bool =>
            preg_match('/^npm error(?:\s|$)/', (string) preg_replace(self::STEP_PREFIX, '', $l)) === 1;
        if (!$isNpmError($lines[$at])) {
            return $at;
        }
        while ($at > 0 && $isNpmError($lines[$at - 1])) {
            $at--;
        }

        return $at;
    }

    private static function isNoise(string $line): bool
    {
        foreach (self::NOISE as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tags compose says it builds (`Image <tag> Building`). That line is noise
     * and is dropped, so the explainer cannot see it (#235).
     *
     * @param list<string> $lines
     * @return array<string, true>
     */
    private static function builtTags(array $lines): array
    {
        $built = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*Image\s+(\S+)\s+Building\b/', $line, $m) === 1) {
                $built[$m[1]] = true;
            }
        }

        return $built;
    }

    /**
     * Compose's pull of a tag it then builds itself: benign, not a missing base image.
     *
     * @param array<string, true> $built
     */
    private static function isPullOfBuiltTag(string $line, array $built): bool
    {
        return $built !== []
            && preg_match('/^\s*Image\s+(\S+)\s+Error\s+failed to resolve reference\b/', $line, $m) === 1
            && isset($built[$m[1]]);
    }
}
