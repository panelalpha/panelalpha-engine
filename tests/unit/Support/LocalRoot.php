<?php

namespace Tests\Support;

use App\System\Privileged\Root;
use App\System\Privileged\RootAccountCompose;
use App\System\Privileged\RootAccountContainer;

/** Answers root broker requests with pa-root-local, as the test's user, using the broker's own action table. */
final class LocalRoot
{
    public const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const ENGINE_API_AREAS = ['account-docker.', 'account-container.', 'account-compose.'];

    private const HOST_BUILDS = ['docker.nodeBuild', 'docker.phpBuild', 'docker.composerInstall'];

    /** @var array<string, array{argv: ?list<string>, native: bool}> */
    private static array $plans = [];

    public static function client(): string
    {
        return dirname(__DIR__, 3).'/dockerfiles/root-broker/local/pa-root-local';
    }

    /** Undo with {@see answerByBroker()} in tearDown. */
    public static function answerLocally(): void
    {
        Root::answerWith(self::client());
    }

    public static function answerWith(string $client): void
    {
        Root::answerWith($client);
    }

    public static function answerByBroker(): void
    {
        Root::answerWith(null);
    }

    /** @param  list<string>  $argv */
    public static function isRequest(array $argv): bool
    {
        return Root::askedAction(array_values($argv)) !== null;
    }

    /**
     * `area.action` of a broker request, null for any other command.
     *
     * @param  list<string>  $argv
     */
    public static function action(array $argv): ?string
    {
        return Root::askedAction(array_values($argv));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function values(array $argv): array
    {
        return Root::askedValues(self::asked($argv));
    }

    /**
     * Routes a broker request to pa-root-local.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function argv(array $argv): array
    {
        $argv = array_values($argv);

        return self::isRequest($argv) ? [self::client(), ...array_slice($argv, 1)] : $argv;
    }

    /** Routes every broker request in a shell line to pa-root-local. */
    public static function line(string $line): string
    {
        return str_replace(self::CLIENT, self::client(), $line);
    }

    /**
     * The command the broker would run for a request: [] for a native action, `pa-root <request>` for an
     * `account-docker`, `account-container` or `account-compose` one, so a test's shim answers it.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function plain(array $argv): array
    {
        return self::command(array_values($argv), 'plan');
    }

    /**
     * {@see plain()} once each of the request's paths is below a root its action allows, as the broker checks it first:
     * a request the broker refuses throws with the broker's refusal.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function checked(array $argv): array
    {
        return self::command($argv, 'check');
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function command(array $argv, string $mode): array
    {
        if (! self::isRequest($argv)) {
            return $argv;
        }
        $planned = self::plan($argv, $mode)['argv'] ?? null;
        if ($planned === null && self::isEngineApiRequest((string) self::action($argv))) {
            return ['pa-root', ...array_slice($argv, 1)];
        }

        return $planned ?? [];
    }

    /**
     * A host build request (`docker.nodeBuild`, `docker.phpBuild`, `docker.composerInstall`) as the broker reads it;
     * [] for any other request. `mounts` are `source:target`, read-only ones `source:target:ro`.
     *
     * @param  list<string>  $argv
     * @return array{labels: array<string, string>, user: string, memory: string, network: string, mounts: list<string>, workdir: string, environment: array<string, string>, image: string, script: string}
     */
    public static function build(array $argv): array
    {
        if (! in_array(self::action($argv), self::HOST_BUILDS, true)) {
            return [];
        }
        $values = self::values($argv);
        $at = 0;
        $labels = [];
        foreach (self::pairs($values, $at) as [$name, $value]) {
            $labels[$name] = $value;
        }
        $user = (string) ($values[$at++] ?? '');
        $memory = (string) ($values[$at++] ?? '');
        $network = (string) ($values[$at++] ?? '');
        $mounts = [];
        foreach (self::pairs($values, $at) as [$target, $source]) {
            $mounts[] = $source.':'.$target;
        }
        foreach (self::pairs($values, $at) as [$target, $source]) {
            $mounts[] = $source.':'.$target.':ro';
        }
        $workdir = (string) ($values[$at++] ?? '');
        $environment = [];
        foreach (self::pairs($values, $at) as [$name, $value]) {
            $environment[$name] = $value;
        }

        return [
            'labels' => $labels,
            'user' => $user,
            'memory' => $memory,
            'network' => $network,
            'mounts' => $mounts,
            'workdir' => $workdir,
            'environment' => $environment,
            'image' => (string) ($values[$at++] ?? ''),
            'script' => (string) ($values[$at] ?? ''),
        ];
    }

    /**
     * A `build-cache.buildKit` request as the broker reads it; [] for any other request. `mounts` are
     * `source:target`, read-only ones `source:target:ro`.
     *
     * @param  list<string>  $argv
     * @return array{network: string, memory: string, pids: string, mounts: list<string>, arguments: list<string>}
     */
    public static function buildKit(array $argv): array
    {
        if (self::action($argv) !== 'build-cache.buildKit') {
            return [];
        }
        $values = self::values($argv);
        $at = 0;
        $network = (string) ($values[$at++] ?? '');
        $memory = (string) ($values[$at++] ?? '');
        $pids = (string) ($values[$at++] ?? '');
        $mounts = [];
        foreach (self::pairs($values, $at) as [$target, $source]) {
            $mounts[] = $source.':'.$target.':ro';
        }
        foreach (self::pairs($values, $at) as [$target, $source]) {
            $mounts[] = $source.':'.$target;
        }

        return [
            'network' => $network,
            'memory' => $memory,
            'pids' => $pids,
            'mounts' => $mounts,
            'arguments' => array_map(strval(...), array_slice($values, $at)),
        ];
    }

    /**
     * What an `account-container.exec` request, or its {@see plain()} form, runs in the account's container; [] for
     * any other command.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function inAccount(array $argv): array
    {
        $argv = self::asked($argv);

        return RootAccountContainer::isExecRequest($argv) ? RootAccountContainer::commandOf($argv) : [];
    }

    /**
     * The `docker …` command line an `account-compose.run` request, or its {@see plain()} form, runs; [] for any other
     * command.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function composeCommand(array $argv): array
    {
        $argv = self::asked($argv);

        return RootAccountCompose::isRunRequest($argv) ? ['docker', ...RootAccountCompose::commandOf($argv)] : [];
    }

    /**
     * {@see composeCommand()} quoted as a shell reads it; '' for any other command.
     *
     * @param  list<string>  $argv
     */
    public static function composeLine(array $argv): string
    {
        return implode(' ', array_map(escapeshellarg(...), self::composeCommand($argv)));
    }

    /**
     * The environment an `account-container.exec` or `account-compose.run` request, or its {@see plain()} form, sets;
     * [] for any other command.
     *
     * @param  list<string>  $argv
     * @return array<string, string>
     */
    public static function accountEnvironment(array $argv): array
    {
        $argv = self::asked($argv);
        $at = match (true) {
            RootAccountContainer::isExecRequest($argv) => 3,
            RootAccountCompose::isRunRequest($argv) => 3,
            default => null,
        };
        if ($at === null) {
            return [];
        }
        $values = self::values($argv);
        $environment = [];
        for ($i = 0; $i < (int) ($values[$at] ?? 0); $i++) {
            $environment[$values[$at + 1 + 2 * $i]] = $values[$at + 2 + 2 * $i];
        }

        return $environment;
    }

    /**
     * The counted pairs starting at $at, as `[$key, $value]`, advancing it.
     *
     * @param  list<string>  $values
     * @return list<array{string, string}>
     */
    private static function pairs(array $values, int &$at): array
    {
        $count = (int) ($values[$at++] ?? 0);
        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [(string) ($values[$at++] ?? ''), (string) ($values[$at++] ?? '')];
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function asked(array $argv): array
    {
        $argv = array_values($argv);

        return ($argv[0] ?? null) === 'pa-root' ? [self::CLIENT, ...array_slice($argv, 1)] : $argv;
    }

    private static function isEngineApiRequest(string $action): bool
    {
        foreach (self::ENGINE_API_AREAS as $area) {
            if (str_starts_with($action, $area)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@see plain()} for the one request in a gateway shell line.
     *
     * @return list<string>
     */
    public static function plainLine(string $line): array
    {
        $pipes = [];
        $process = proc_open(['sh', '-c', self::line($line)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(), 'PA_ROOT_LOCAL' => 'plan']);
        if (! is_resource($process)) {
            throw new \RuntimeException('pa-root-local did not start');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('pa-root-local: '.trim($err));
        }
        /** @var array{argv: list<string>} $plan */
        $plan = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        return $plan['argv'];
    }

    /**
     * The broker's `sh -c` script for $request, with its path roots moved.
     *
     * @param  list<string>  $request
     * @param  array<string, string>  $roots  root name => directory
     */
    public static function script(array $request, array $roots = []): string
    {
        $before = getenv('PA_ROOT_LOCAL_ROOTS');
        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode((object) $roots, JSON_THROW_ON_ERROR));
        try {
            $argv = self::plain($request);
        } finally {
            putenv($before === false ? 'PA_ROOT_LOCAL_ROOTS' : 'PA_ROOT_LOCAL_ROOTS='.$before);
        }
        $at = array_search('-c', $argv, true);
        if ($at === false || ! isset($argv[$at + 1])) {
            throw new \RuntimeException('the broker runs no script for '.implode(' ', $request));
        }

        return $argv[$at + 1];
    }

    /** @param  list<string>  $argv */
    public static function isNative(array $argv): bool
    {
        return self::isRequest($argv) && self::plan(array_values($argv))['native'];
    }

    /**
     * @param  list<string>  $argv
     * @return array{argv: ?list<string>, native: bool}
     */
    private static function plan(array $argv, string $mode = 'plan'): array
    {
        $key = json_encode([$argv, getenv('PA_ROOT_LOCAL_ROOTS'), $mode], JSON_THROW_ON_ERROR);

        return self::$plans[$key] ??= self::planned($argv, $mode);
    }

    /**
     * @param  list<string>  $argv
     * @return array{argv: ?list<string>, native: bool}
     */
    private static function planned(array $argv, string $mode): array
    {
        $pipes = [];
        $process = proc_open(
            ['python3', self::client(), ...array_slice($argv, 1)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'PA_ROOT_LOCAL' => $mode],
        );
        if (! is_resource($process)) {
            throw new \RuntimeException('pa-root-local did not start');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('pa-root-local: '.trim($err));
        }
        /** @var array{argv: ?list<string>, native: bool} $plan */
        $plan = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        return $plan;
    }
}
