<?php

namespace App\System\Privileged;

/**
 * Builds root broker requests. The broker builds the command from the action's name and values.
 *
 * @internal
 */
final class Root
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const HOST = '--host';

    private const TERMINAL = '--pty';

    private const SAFE_WORD = '~\A[A-Za-z0-9_/.:=+@%,-]+\z~';

    private static ?string $client = null;

    /**
     * `pa-root [--host | --pty] [--env NAME]... AREA.ACTION -- VALUE...`.
     * $passing names caller environment variables the action receives outside its argv.
     *
     * @param  array<int, string>  $values
     * @param  list<string>  $passing
     * @return list<string>
     */
    public static function action(
        string $area,
        string $action,
        array $values,
        bool $inHostNamespaces = false,
        array $passing = [],
        bool $terminal = false,
    ): array {
        if ($inHostNamespaces && $terminal) {
            throw InvalidArgument::of('terminal in the host\'s namespaces', $area.'.'.$action);
        }
        $environment = [];
        foreach ($passing as $name) {
            array_push($environment, '--env', Arguments::environmentName($name));
        }

        return [
            self::client(),
            ...($inHostNamespaces ? [self::HOST] : []),
            ...($terminal ? [self::TERMINAL] : []),
            ...$environment,
            $area.'.'.$action, '--', ...array_values($values),
        ];
    }

    /**
     * The request as one shell line, quoting every word that is not plainly safe.
     *
     * @param  list<string>  $values
     */
    public static function actionLine(string $area, string $action, array $values): string
    {
        return implode(' ', array_map(self::shellWord(...), self::action($area, $action, $values)));
    }

    /**
     * `area.action` of a broker request, null for any other command.
     *
     * @param  array<int, string>  $argv
     */
    public static function askedAction(array $argv): ?string
    {
        $argv = array_values($argv);
        $at = self::actionIndex($argv);

        return $at === null ? null : $argv[$at];
    }

    /**
     * The request's values, empty for any other command.
     *
     * @param  array<int, string>  $argv
     * @return list<string>
     */
    public static function askedValues(array $argv): array
    {
        $argv = array_values($argv);
        $at = self::actionIndex($argv);

        return $at === null ? [] : array_slice($argv, $at + 2);
    }

    /**
     * The same request carrying $values instead.
     *
     * @param  array<int, string>  $argv
     * @param  array<int, string>  $values
     * @return list<string>
     */
    public static function reasked(array $argv, array $values): array
    {
        $argv = array_values($argv);
        $at = self::actionIndex($argv) ?? throw InvalidArgument::of('broker request', implode(' ', $argv));

        return [...array_slice($argv, 0, $at + 2), ...array_values($values)];
    }

    /**
     * Adds `--host` to a broker request and refuses any other command.
     *
     * @param  array<int, string>  $argv
     * @return list<string>
     */
    public static function inHostNamespaces(array $argv): array
    {
        $argv = array_values($argv);
        if (self::actionIndex($argv) === null || ($argv[1] ?? null) === self::TERMINAL) {
            throw InvalidArgument::of('command for the host', implode(' ', $argv));
        }

        return self::isOnHost($argv) ? $argv : [$argv[0], self::HOST, ...array_slice($argv, 1)];
    }

    /** @param  array<int, string>  $argv */
    public static function isOnHost(array $argv): bool
    {
        $argv = array_values($argv);

        return self::actionIndex($argv) !== null && $argv[1] === self::HOST;
    }

    /** @param  array<int, string>  $argv */
    public static function isRequest(array $argv): bool
    {
        return self::actionIndex(array_values($argv)) !== null;
    }

    /** What the broker's client said about a request it refused, without its own `pa-root: refused:` prefix. */
    public static function refusalReason(string $said): string
    {
        return trim((string) preg_replace('/^pa-root:\s*(?:refused:\s*)?/', '', trim($said)));
    }

    /** Replaces pa-root for tests and the dind-test harness, e.g. with pa-root-local. Null restores it. */
    public static function answerWith(?string $client): void
    {
        self::$client = $client;
    }

    public static function shellWord(string $word): string
    {
        return preg_match(self::SAFE_WORD, $word) === 1 ? $word : escapeshellarg($word);
    }

    private static function client(): string
    {
        return self::$client ?? self::CLIENT;
    }

    /** @param  list<string>  $argv */
    private static function actionIndex(array $argv): ?int
    {
        if (($argv[0] ?? null) !== self::client()) {
            return null;
        }
        $at = 1;
        foreach ([self::HOST, self::TERMINAL] as $flag) {
            if (($argv[$at] ?? null) === $flag) {
                $at++;
            }
        }
        while (($argv[$at] ?? null) === '--env') {
            $at += 2;
        }

        return isset($argv[$at]) && ($argv[$at + 1] ?? null) === '--' ? $at : null;
    }
}
