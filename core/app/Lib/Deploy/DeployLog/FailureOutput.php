<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * The part of a failed build's output that says why: the first announcing line in the build's last attempt, and what followed.
 * Only decides what {@see DeployFailureExplainer} is given: its rules match anywhere, so the whole stderr let a stray warning win.
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

    /** How much of a failed build step's own output to keep: a cause can sit dozens of lines up, under a backtrace. */
    private const STEP_LINES = 60;

    /**
     * A build step its memory limit stopped. The compile errors printed above
     * it are symptoms, and can be further up than CONTEXT reaches.
     */
    private const MEMORY_EXHAUSTED = '/(?:ResourceExhausted:|did not complete successfully:)[^\n]*cannot allocate memory/';

    /** BuildKit's `#<step> <seconds> ` in front of a step's output line, or all of an empty one. */
    private const STEP_PREFIX = '/^#\d+ \d+(?:\.\d+)?(?: |$)/';

    /** How many of a failed step's last lines tell a failure no rule names apart. */
    private const STEP_TAIL = 6;

    /**
     * A line that says "this is where it died", anchored so prose cannot match: `ERR!` is pnpm, `error:` python or a shell, `ERROR`/`FATAL`
     * webpack or gradle; Maven only by goal and summary lines (all carry a bracket), Go by `.go:` or the last wrapped `imports`, tar's owner refusal before Flutter's advice.
     */
    private const CAUSE = [
        '/^#\d+ ERROR:/',
        '/Cannot change ownership to uid \d+, gid \d+: Invalid argument/',
        '/^failed to solve:/',
        '/^Error response from daemon:/',
        '/^runc (?:create|run) failed:/',
        '/^E: (?!List directory \/var\/lib\/apt\/lists\/partial is missing)/',
        '/^npm error (?!npm error)/',
        '/^npm ERR!/',
        '/^gyp ERR!/',
        '/^\s*error Command failed/i',
        '/^ERR!/',
        '/^\s*error:/i',
        '/^(?:ERROR|FATAL)(?:\s|:)/',
        '/^\[ERROR\]\s+Failed to execute goal/',
        '/^\[ERROR\]\s+(?:BUILD FAILURE|Failed to read|Non-resolvable|Internal error)/',
        '/^[\w.\/@-]+\.go:\d+:\d+:/',
        '/^package [\w.\/-]+: build constraints exclude all Go files/',
        '/^\s+imports [\w.\/-]+\S*: (?:build constraints|no required module|cannot find)/',
        '/^no Go files in \//',
        '/^go: go\.mod requires go >= /',
        '/^error: cannot find module providing package /',
        '/^FAILURE: Build failed/',
        '/^\* What went wrong:/',
        '/^failed to pull /',
        '/^unable to get image /',
        '/^service "[^"]+" has neither /',
        '/^dependency failed to start:/',
        '/^service "[^"]+" didn\'t complete successfully/',
        '/^gyp: /',
        '/^\s*PHP Fatal error:/i',
        '/^Traceback \(most recent call last\)/',
        '/^LoadError: cannot load such file -- /',
        '/^fatal:/',
        '/^(?:Memory cgroup )?[Oo]ut of memory\b/',
        '/^Killed\b/',
    ];

    /**
     * Benign lines that must never lead: advisory warnings (npm's installs anyway), progress, banners, permission notices a host build expects, and
     * a Makefile's `git describe` without a .git. Never `#N ERROR`, npm's error object, `level=error`, Maven's `[ERROR]`, another `mkdir` or `ERROR:`, or real git failures.
     */
    private const NOISE = [
        '/^npm warn\b/i',
        '/^npm notice\b/i',
        '/^warning\s/i',
        '/^Picked up (?:JAVA_TOOL_OPTIONS|_JAVA_OPTIONS)/',
        '/^time="[^"]*" level=(?:debug|info|warning)\b/',
        '/^#\d+ (?:DONE|CACHED)\b/',
        '/^#\d+ \[[^\]]*\]\s/',
        '/^<s> /',
        '/^\[\d+\.\d+s\]\s*$/',
        '/^\[INFO\]/',
        '/^mkdir: cannot create directory (?:\'|"|‘)\/root(?:\'|"|’):/',
        '/^mkdir: Permission denied$/',
        '/^Can not write to \S+\/copy_reference_file\.log\. Wrong volume permissions\? Carrying on \.\.\.$/',
        '/^ERROR: Unable to open log: Permission denied$/',
        '/^\s*(?:Image|Network|Volume|Container)\s+\S+\s+(?:Pulling|Pulled|Building|Built|Creating|Created'
            .'|Starting|Started|Waiting|Healthy|Running|Recreate|Recreated|Stopping|Stopped|Removing|Removed'
            .'|Skipped)(?:\s+[\d.]+s)?\s*$/',
        '/^\s*[0-9a-f]{12}\s+(?:Pulling fs layer|Waiting|Downloading|Download complete|Verifying Checksum'
            .'|Extracting|Pull complete|Already exists)\b/',
        '/^Unable to find image \'[^\']+\' locally$/',
        '/^[\w.-]+: Pulling from [\w.\/:-]+$/',
        '/^[0-9a-f]{12}: (?:Pulling fs layer|Waiting|Downloading|Download complete|Verifying Checksum'
            .'|Extracting|Pull complete|Already exists)\b/',
        '/^Digest: sha256:[0-9a-f]{64}$/',
        '/^Status: (?:Downloaded newer image|Image is up to date) for \S+$/',
        '/^E: List directory \/var\/lib\/apt\/lists\/partial is missing\. - Acquire \(13: Permission denied\)$/',
        '/^(?:#\d+ \d+(?:\.\d+)? )?fatal: not a git repository\b/',
        '/^\s*$/',
    ];

    /**
     * The region of $output to explain. When nothing announces a cause (Composer's `Your requirements could not be resolved` does
     * not), the whole window is returned: bounded to WINDOW non-noise lines, it keeps whatever the explainer can recognise.
     */
    public static function select(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $built = self::builtTags($lines);
        $lines = array_values(array_filter(
            $lines,
            static fn (string $l): bool => ! self::isNoise($l) && ! self::isPullOfBuiltTag($l, $built)
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

        return self::causeRegion($lines, $window) ?? trim(implode("\n", $window));
    }

    /**
     * The region around the first line in $window that announces a cause, a step's output matched without its BuildKit prefix.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $window
     */
    private static function causeRegion(array $lines, array $window): ?string
    {
        $offset = count($lines) - count($window);
        foreach ($window as $i => $line) {
            if (self::isCause($line)) {
                $start = self::npmBlockStart($lines, $offset + $i);

                return self::stepCause($lines, $offset + $i)
                    ?? trim(implode("\n", array_slice($lines, $start, self::CONTEXT)));
            }
        }

        return null;
    }

    /**
     * The output without its progress and advisory lines, for a failure no rule matched: a host build's stderr opens with docker's
     * image pull. Nothing else is dropped, as the text may be a sentence the engine already made.
     */
    public static function withoutNoise(string $output): string
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        $kept = array_filter($lines, static fn (string $l): bool => ! self::isNoise($l));

        return $kept === [] ? trim($output) : trim(implode("\n", $kept));
    }

    /**
     * The `#N` lines of the first step that reported `#N ERROR:` in `docker compose up --build`'s stdout: Compose writes the build
     * progress there and only the `failed to solve` summary to stderr, so the explainer saw an exit code and never the cause.
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
            static fn (string $l): bool => str_starts_with($l, '#'.$step.' ')
        ));

        return trim(implode("\n", array_slice($own, -self::STEP_LINES)));
    }

    /**
     * The last lines a failed BuildKit step printed above its bare failure lines, without prefixes: where the cause is
     * when no rule names it. Empty without a failed step.
     */
    public static function failedStepTail(string $output): string
    {
        $own = preg_split('/\r?\n/', self::failedBuildStep($output)) ?: [];
        $printed = array_filter(
            array_map(static fn (string $l): string => (string) preg_replace(self::STEP_PREFIX, '', $l), $own),
            static fn (string $l): bool => ! self::isNoise($l) && ! self::isCause($l)
        );

        return trim(implode("\n", array_slice($printed, -self::STEP_TAIL)));
    }

    /**
     * The text a failed build process is reported by: its stderr, unless that is nothing but noise, as with Maven, whose image and
     * JVM banners go to stderr while its `[ERROR]` lines go to stdout.
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
     * When `#N ERROR:` leads, the cause is in what step N printed above it, which no CAUSE pattern knows: the region starts at the
     * shortest tail of the step an explainer rule still recognises, or null, leaving the region at `#N ERROR:`.
     *
     * @param  list<string>  $lines
     */
    private static function stepCause(array $lines, int $at): ?string
    {
        if (preg_match('/^#(\d+) ERROR:/', $lines[$at], $m) !== 1) {
            return null;
        }
        $own = array_values(array_filter(
            array_slice($lines, 0, $at),
            static fn (string $l): bool => str_starts_with($l, '#'.$m[1].' ')
        ));
        $rule = DeployFailureExplainer::match(implode("\n", $own))['rule'] ?? null;
        if ($rule === null) {
            return null;
        }

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
     * Where the `npm error` block holding line $at begins: npm 11 prints a command's whole usage after EUSAGE, longer than WINDOW,
     * which cut off `npm error code` and its reason.
     *
     * @param  list<string>  $lines
     */
    private static function npmBlockStart(array $lines, int $at): int
    {
        $isNpmError = static fn (string $l): bool => preg_match('/^npm error(?:\s|$)/', (string) preg_replace(self::STEP_PREFIX, '', $l)) === 1;
        if (! $isNpmError($lines[$at])) {
            return $at;
        }
        while ($at > 0 && $isNpmError($lines[$at - 1])) {
            $at--;
        }

        return $at;
    }

    /** A build step's own output carries BuildKit's `#13 249.2 ` prefix, so the bare line is matched too. */
    private static function isCause(string $line): bool
    {
        $bare = (string) preg_replace(self::STEP_PREFIX, '', $line);
        foreach (self::CAUSE as $pattern) {
            if (preg_match($pattern, $line) === 1 || preg_match($pattern, $bare) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A `mkdir` of /root matches coreutils' ‘’ quotes too, three bytes each, which a bare `.` never matched. */
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
     * and is dropped, so the explainer cannot see it.
     *
     * @param  list<string>  $lines
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
     * @param  array<string, true>  $built
     */
    private static function isPullOfBuiltTag(string $line, array $built): bool
    {
        return $built !== []
            && preg_match('/^\s*Image\s+(\S+)\s+Error\s+failed to resolve reference\b/', $line, $m) === 1
            && isset($built[$m[1]]);
    }
}
