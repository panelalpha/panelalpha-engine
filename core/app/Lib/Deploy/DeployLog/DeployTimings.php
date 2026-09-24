<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Where a deploy spent its time: per stage, and per build layer.
 *
 * A step reported `CACHED` cost nothing; a step that compiled again after an
 * identical deploy is the bug a total hides.
 */
final class DeployTimings
{
    /**
     * Verb pairs Compose prints around each thing it does.
     *
     * @var array<string, string> start verb => finish verb
     */
    private const COMPOSE_ACTIONS = [
        'Pulling' => 'Pulled',
        'Building' => 'Built',
        'Creating' => 'Created',
        'Starting' => 'Started',
        'Recreating' => 'Recreated',
    ];

    /** Log level for raw subprocess output, which is not a milestone. */
    private const LEVEL_DIM = 'dim';

    /**
     * The three recorded stages are too coarse to act on: `running` is one blob
     * covering detection, base images, the build and the app boot. These markers
     * split it using lines the pipeline already writes.
     *
     * @var array<string, string> phase => regex over the message
     */
    private const MARKERS = [
        'start' => '/^Deploy started/',
        'preparing_end' => "/^Stage 'preparing' finished/",
        'cloning_start' => '/^Starting stage: cloning/',
        'cloning_end' => "/^Stage 'cloning' finished/",
        'running_start' => '/^Starting stage: running/',
        'detected' => '/^Detected project type/',
        'base_image' => '/^(Building|Preparing) shared .*base image|^(Fetching|Loaded|Pulled) base image/',
        'app_start' => '/^Starting application/',
        'answered' => '/^Health check:.*answered/',
        'finished' => '/^Deploy finished/',
    ];

    /**
     * Stage durations from the boundaries {@see DeployLogger::stage()} writes.
     *
     * A stage still running has no `finished_at` and is reported with a null
     * duration rather than timed against `now`.
     *
     * @param array<string, mixed> $latest decoded latest.json
     * @return list<array{name: string, started_at: ?int, finished_at: ?int, seconds: ?int}>
     */
    public static function stages(array $latest): array
    {
        $stages = $latest['stages'] ?? null;
        if (!is_array($stages)) {
            return [];
        }

        $out = [];
        foreach ($stages as $stage) {
            if (!is_array($stage) || !isset($stage['name']) || !is_string($stage['name'])) {
                continue;
            }
            $started = isset($stage['started_at']) ? (int) $stage['started_at'] : null;
            $finished = isset($stage['finished_at']) && $stage['finished_at'] !== null
                ? (int) $stage['finished_at']
                : null;
            $out[] = [
                'name' => $stage['name'],
                'started_at' => $started,
                'finished_at' => $finished,
                'seconds' => $started !== null && $finished !== null ? max(0, $finished - $started) : null,
            ];
        }

        return $out;
    }

    /**
     * What `docker compose up` spent its time on, object by object.
     *
     * Compose announces `<Kind> <name> <Verb>` around everything it does, and
     * pairing the verbs turns that into durations: a 118s compose-up was 116s
     * `Image project-app Building`, against about a second each for the network
     * and the container.
     *
     * @param list<array{ts: int, msg: string}> $entries
     * @return list<array{object: string, action: string, seconds: int}>
     */
    public static function composeSteps(array $entries): array
    {
        $started = [];
        $out = [];

        foreach ($entries as $entry) {
            $ts = (int) ($entry['ts'] ?? 0);
            $msg = trim((string) ($entry['msg'] ?? ''));
            if ($ts === 0
                || preg_match('/^(Image|Network|Container|Volume)\s+(\S+)\s+(\w+)$/', $msg, $m) !== 1
            ) {
                continue;
            }

            [, $kind, $name, $verb] = $m;
            $object = "{$kind} {$name}";

            if (isset(self::COMPOSE_ACTIONS[$verb])) {
                $started[$object . '|' . $verb] = $ts;
                continue;
            }

            foreach (self::COMPOSE_ACTIONS as $begin => $end) {
                if ($verb !== $end) {
                    continue;
                }
                $key = $object . '|' . $begin;
                if (isset($started[$key])) {
                    $out[] = [
                        'object' => $object,
                        'action' => strtolower($begin),
                        'seconds' => max(0, $ts - $started[$key]),
                    ];
                    unset($started[$key]);
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $out;
    }

    /**
     * Every measurable step, in order, with how long it took.
     *
     * A milestone is any line the pipeline announced (`info`, `ok`, `warn`,
     * `error`); `dim` lines are raw output and number in the thousands. Each is
     * charged the time until the next one. {@see phases()} groups this into six
     * buckets; {@see buildSteps()} covers the inside of the build.
     *
     * @param list<array{ts: int, level?: string, msg: string}> $entries
     * @return list<array{at: int, seconds: ?int, level: string, step: string}>
     */
    public static function timeline(array $entries): array
    {
        $milestones = [];
        foreach ($entries as $entry) {
            $level = (string) ($entry['level'] ?? self::LEVEL_DIM);
            $msg = trim((string) ($entry['msg'] ?? ''));
            $ts = (int) ($entry['ts'] ?? 0);
            if ($ts === 0 || $msg === '' || $level === self::LEVEL_DIM) {
                continue;
            }
            $milestones[] = ['ts' => $ts, 'level' => $level, 'step' => $msg];
        }
        if ($milestones === []) {
            return [];
        }

        $start = $milestones[0]['ts'];
        $out = [];
        foreach ($milestones as $i => $milestone) {
            $next = $milestones[$i + 1]['ts'] ?? null;
            $out[] = [
                'at' => $milestone['ts'] - $start,
                // The last milestone closes nothing, so it is charged nothing.
                'seconds' => $next === null ? null : max(0, $next - $milestone['ts']),
                'level' => $milestone['level'],
                'step' => $milestone['step'],
            ];
        }

        return $out;
    }

    /**
     * Where the time went, phase by phase.
     *
     * `build` is the sum of BuildKit's layer times rather than wall-clock,
     * because the compose-up window contains both the build and the boot and
     * they are fixed by different people. `start_to_answer` is that window with
     * the build subtracted: container start, entrypoint, migrations, reachability.
     *
     * @param list<array{ts: int, msg: string}> $entries
     * @param list<array{step: string, command: string, seconds: float, cached: bool}> $steps
     * @return list<array{name: string, seconds: float}>
     */
    public static function phases(array $entries, array $steps = []): array
    {
        $first = [];
        $last = [];
        foreach ($entries as $entry) {
            $ts = (int) ($entry['ts'] ?? 0);
            $msg = trim((string) ($entry['msg'] ?? ''));
            if ($ts === 0 || $msg === '') {
                continue;
            }
            foreach (self::MARKERS as $name => $pattern) {
                if (preg_match($pattern, $msg) === 1) {
                    $first[$name] ??= $ts;
                    $last[$name] = $ts;
                }
            }
        }

        $span = static fn (?int $from, ?int $to): ?float =>
            $from === null || $to === null ? null : (float) max(0, $to - $from);

        $buildSeconds = 0.0;
        foreach ($steps as $step) {
            $buildSeconds += (float) $step['seconds'];
        }

        $startToAnswer = $span($first['app_start'] ?? null, $last['answered'] ?? null);
        if ($startToAnswer !== null) {
            // The build happens inside this window; charging it twice would make
            // the phases sum to more than the deploy took.
            $startToAnswer = max(0.0, $startToAnswer - $buildSeconds);
        }

        $phases = [
            'preparing' => $span($first['start'] ?? null, $first['preparing_end'] ?? null),
            'cloning' => $span($first['cloning_start'] ?? null, $first['cloning_end'] ?? null),
            'detect' => $span($first['running_start'] ?? null, $first['detected'] ?? null),
            'image_transfer' => $span($first['base_image'] ?? null, $last['base_image'] ?? null),
            'build' => $steps === [] ? null : round($buildSeconds, 1),
            'start_to_answer' => $startToAnswer,
        ];

        $out = [];
        foreach ($phases as $name => $seconds) {
            if ($seconds !== null) {
                $out[] = ['name' => $name, 'seconds' => round((float) $seconds, 1)];
            }
        }

        return $out;
    }

    /**
     * Build layers, slowest first, from BuildKit's progress output.
     *
     * Correlates `#N [stage x/y] <command>` with the `#N DONE <s>` and
     * `#N CACHED` that follow it. BuildKit's own `[internal]` steps are dropped:
     * they are not layers anyone can make faster.
     *
     * @param list<string> $lines log message lines, in order
     * @return list<array{step: string, command: string, seconds: float, cached: bool}>
     */
    public static function buildSteps(array $lines): array
    {
        $commands = [];
        $seconds = [];
        $cached = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (preg_match('/#(\d+)\s+\[([^\]]*)\]\s+(.+)$/', $line, $m) === 1) {
                if (str_starts_with($m[2], 'internal')) {
                    continue;
                }
                // A bracketed descriptor is the command itself and always wins.
                $commands[$m[1]] = trim($m[3]);
            } elseif (preg_match('/#(\d+)\s+([a-z][^\n]*)$/', $line, $m) === 1) {
                // BuildKit's unbracketed steps -- `exporting to image` above
                // all: real work (19.2s on a 1.36GB Matomo build). Only the
                // first line per step; the rest are its own progress.
                $commands[$m[1]] ??= trim($m[2]);
            } elseif (preg_match('/#(\d+)\s+DONE\s+([\d.]+)s/', $line, $m) === 1) {
                // A step can report DONE more than once as its children finish;
                // the largest is the one that describes the step.
                $seconds[$m[1]] = max($seconds[$m[1]] ?? 0.0, (float) $m[2]);
            } elseif (preg_match('/#(\d+)\s+CACHED/', $line, $m) === 1) {
                $cached[$m[1]] = true;
            }
        }

        $steps = [];
        foreach ($commands as $n => $command) {
            $steps[] = [
                'step' => '#' . $n,
                'command' => $command,
                // A CACHED step scores zero: it cost no build time.
                'seconds' => round($cached[$n] ?? false ? 0.0 : ($seconds[$n] ?? 0.0), 2),
                'cached' => isset($cached[$n]),
            ];
        }

        usort($steps, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $steps;
    }

    /**
     * The whole picture, in the shape the API returns.
     *
     * `cache_hit_ratio` is the number to alert on: 0.0 on a first deploy, high
     * on a repeat of the same commit. A repeat that reports 0.0 means the cache
     * was destroyed between runs.
     *
     * @param array<string, mixed> $latest decoded latest.json
     * @param list<array{ts: int, msg: string}> $entries log entries, in order
     * @return array<string, mixed>
     */
    public static function summarize(array $latest, array $entries = []): array
    {
        $stages = self::stages($latest);
        $steps = self::buildSteps(array_column($entries, 'msg'));

        $cachedCount = 0;
        $buildSeconds = 0.0;
        foreach ($steps as $step) {
            $buildSeconds += $step['seconds'];
            if ($step['cached']) {
                $cachedCount++;
            }
        }

        $started = isset($latest['started_at']) ? (int) $latest['started_at'] : null;
        $finished = isset($latest['finished_at']) && $latest['finished_at'] !== null
            ? (int) $latest['finished_at']
            : null;

        return [
            'total_seconds' => $started !== null && $finished !== null ? max(0, $finished - $started) : null,
            'stages' => $stages,
            'phases' => self::phases($entries, $steps),
            'timeline' => self::timeline($entries),
            'compose' => self::composeSteps($entries),
            'build' => [
                'total_seconds' => round($buildSeconds, 2),
                'step_count' => count($steps),
                'cached_steps' => $cachedCount,
                'cache_hit_ratio' => $steps === [] ? null : round($cachedCount / count($steps), 3),
                // Every layer, not a top ten: a layer that regressed from 0.1s
                // to 30s never appears in a top ten from the run before.
                'steps' => $steps,
                'slowest' => array_slice($steps, 0, 10),
            ],
        ];
    }
}
