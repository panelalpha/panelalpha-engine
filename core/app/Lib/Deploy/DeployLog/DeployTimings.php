<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Where a deploy spent its time, per stage and per build layer. A `CACHED` step cost nothing; one that compiled again after
 * an identical deploy is the bug a total hides.
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
     * Lines the pipeline already writes that split the recorded stages, too coarse to act on: `running` alone covers detection,
     * base images, the build and the app boot.
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
        'base_image' => '/^(Building|Preparing) shared .*base image|^(Fetching|Loaded|Pulled) base image|^Using shared base image/',
        'app_start' => '/^Starting application/',
        'answered' => '/^Health check:.*answered/',
        'finished' => '/^Deploy finished/',
    ];

    /**
     * Stage durations from the boundaries {@see DeployLogger::stage()} writes. A stage still running has no `finished_at` and a
     * null duration.
     *
     * @param  array<string, mixed>  $latest  decoded latest.json
     * @return list<array{name: string, started_at: ?int, finished_at: ?int, seconds: ?int}>
     */
    public static function stages(array $latest): array
    {
        $stages = $latest['stages'] ?? null;
        if (! is_array($stages)) {
            return [];
        }

        $out = [];
        foreach ($stages as $stage) {
            if (! is_array($stage) || ! isset($stage['name']) || ! is_string($stage['name'])) {
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
     * What `docker compose up` spent its time on, object by object: Compose announces `<Kind> <name> <Verb>` around everything it
     * does, and pairing the verbs turns that into durations.
     *
     * @param  list<array{ts: int, msg: string}>  $entries
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

            self::pairComposeVerb($object, $verb, $ts, $started, $out);
        }

        usort($out, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $out;
    }

    /**
     * Note when $object started an action, or record the action a finish verb closes.
     *
     * @param  array<string, int>  $started  `<object>|<start verb>` => timestamp, updated in place
     * @param  list<array{object: string, action: string, seconds: int}>  $out  appended to in place
     */
    private static function pairComposeVerb(string $object, string $verb, int $ts, array &$started, array &$out): void
    {
        if (isset(self::COMPOSE_ACTIONS[$verb])) {
            $started[$object.'|'.$verb] = $ts;

            return;
        }

        foreach (self::COMPOSE_ACTIONS as $begin => $end) {
            if ($verb !== $end) {
                continue;
            }
            $key = $object.'|'.$begin;
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

    /**
     * Every milestone (`info`, `ok`, `warn` or `error`; `dim` is raw output, in the thousands), charged the time until the next;
     * the last closes nothing and is charged nothing. {@see phases()} groups it, {@see buildSteps()} covers the build.
     *
     * @param  list<array{ts: int, level?: string, msg: string}>  $entries
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
                'seconds' => $next === null ? null : max(0, $next - $milestone['ts']),
                'level' => $milestone['level'],
                'step' => $milestone['step'],
            ];
        }

        return $out;
    }

    /**
     * Where the time went, phase by phase. `build` sums BuildKit's layer times, as the compose-up window holds the build and the
     * boot, fixed by different people; `start_to_answer` is that window minus the build, which would otherwise count twice.
     *
     * @param  list<array{ts: int, msg: string}>  $entries
     * @param  list<array{step: string, command: string, seconds: float, cached: bool, cacheable: bool}>  $steps
     * @return list<array{name: string, seconds: float}>
     */
    public static function phases(array $entries, array $steps = []): array
    {
        [$first, $last] = self::markerTimes($entries);

        $span = static fn (?int $from, ?int $to): ?float => $from === null || $to === null ? null : (float) max(0, $to - $from);

        $buildSeconds = 0.0;
        foreach ($steps as $step) {
            $buildSeconds += (float) $step['seconds'];
        }

        $startToAnswer = $span($first['app_start'] ?? null, $last['answered'] ?? null);
        if ($startToAnswer !== null) {
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

        return self::measuredPhases($phases);
    }

    /**
     * When each marker was first and last seen.
     *
     * @param  list<array{ts: int, msg: string}>  $entries
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private static function markerTimes(array $entries): array
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

        return [$first, $last];
    }

    /**
     * The phases a log shows, rounded: one that did not happen is left out, not zeroed, which would read as instant.
     *
     * @param  array<string, float|int|null>  $phases
     * @return list<array{name: string, seconds: float}>
     */
    private static function measuredPhases(array $phases): array
    {
        $out = [];
        foreach ($phases as $name => $seconds) {
            if ($seconds !== null) {
                $out[] = ['name' => $name, 'seconds' => round((float) $seconds, 1)];
            }
        }

        return $out;
    }

    /**
     * Build layers, slowest first, each with its largest `#N DONE` (children report too) or `#N CACHED`; `[internal]` steps are
     * dropped. Only a positioned Dockerfile instruction other than `FROM` is `cacheable`, as BuildKit never caches the rest.
     *
     * @param  list<string>  $lines  log message lines, in order
     * @return list<array{step: string, command: string, seconds: float, cached: bool, cacheable: bool}>
     */
    public static function buildSteps(array $lines): array
    {
        $commands = [];
        $seconds = [];
        $cached = [];
        $cacheable = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (preg_match('/#(\d+)\s+\[([^\]]*)\]\s+(.+)$/', $line, $m) === 1) {
                if (str_starts_with($m[2], 'internal')) {
                    continue;
                }
                $commands[$m[1]] = trim($m[3]);
                $cacheable[$m[1]] = self::isCacheableLayer($m[2], $commands[$m[1]]);
            } elseif (preg_match('/#(\d+)\s+([a-z][^\n]*)$/', $line, $m) === 1) {
                $commands[$m[1]] ??= trim($m[2]);
            } elseif (preg_match('/#(\d+)\s+DONE\s+([\d.]+)s/', $line, $m) === 1) {
                $seconds[$m[1]] = max($seconds[$m[1]] ?? 0.0, (float) $m[2]);
            } elseif (preg_match('/#(\d+)\s+CACHED/', $line, $m) === 1) {
                $cached[$m[1]] = true;
            }
        }

        $steps = self::stepRows($commands, $seconds, $cached, $cacheable);

        usort($steps, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $steps;
    }

    /**
     * Whether a bracketed step is a Dockerfile instruction BuildKit can answer from its layer cache: one with an `x/y` position
     * (`[2/3]`, `[app 4/13]`), and not the `FROM` that only resolves the base image.
     */
    private static function isCacheableLayer(string $descriptor, string $command): bool
    {
        return preg_match('#(^|\s)\d+/\d+$#', trim($descriptor)) === 1
            && preg_match('/^FROM\s/i', $command) !== 1;
    }

    /**
     * One row per step, in the order the steps appeared; a CACHED step scores zero, as it cost no build time.
     *
     * @param  array<int|string, string>  $commands
     * @param  array<int|string, float>  $seconds
     * @param  array<int|string, true>  $cached
     * @param  array<int|string, bool>  $cacheable
     * @return list<array{step: string, command: string, seconds: float, cached: bool, cacheable: bool}>
     */
    private static function stepRows(array $commands, array $seconds, array $cached, array $cacheable): array
    {
        $steps = [];
        foreach ($commands as $n => $command) {
            $steps[] = [
                'step' => '#'.$n,
                'command' => $command,
                'seconds' => round($cached[$n] ?? false ? 0.0 : ($seconds[$n] ?? 0.0), 2),
                'cached' => isset($cached[$n]),
                'cacheable' => $cacheable[$n] ?? false,
            ];
        }

        return $steps;
    }

    /**
     * The whole picture, in the API's shape. `cache_hit_ratio` is the cached share of the cacheable layers, so a repeat of the
     * same commit at 0.0 lost its cache.
     *
     * @param  array<string, mixed>  $latest  decoded latest.json
     * @param  list<array{ts: int, msg: string}>  $entries  log entries, in order
     * @return array<string, mixed>
     */
    public static function summarize(array $latest, array $entries = []): array
    {
        $stages = self::stages($latest);
        $steps = self::buildSteps(array_column($entries, 'msg'));

        [$cachedCount, $cacheableCount, $buildSeconds] = self::buildTotals($steps);

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
                'cacheable_steps' => $cacheableCount,
                'cached_steps' => $cachedCount,
                'cache_hit_ratio' => $cacheableCount === 0 ? null : round($cachedCount / $cacheableCount, 3),
                'steps' => $steps,
                'slowest' => array_slice($steps, 0, 10),
            ],
        ];
    }

    /**
     * How many cacheable layers were cached, how many there were, and the seconds every step took.
     *
     * @param  list<array{step: string, command: string, seconds: float, cached: bool, cacheable: bool}>  $steps
     * @return array{0: int, 1: int, 2: float}
     */
    private static function buildTotals(array $steps): array
    {
        $cachedCount = 0;
        $cacheableCount = 0;
        $buildSeconds = 0.0;
        foreach ($steps as $step) {
            $buildSeconds += $step['seconds'];
            if ($step['cacheable']) {
                $cacheableCount++;
                $cachedCount += $step['cached'] ? 1 : 0;
            }
        }

        return [$cachedCount, $cacheableCount, $buildSeconds];
    }
}
