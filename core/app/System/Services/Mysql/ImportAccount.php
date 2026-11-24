<?php

namespace App\System\Services\Mysql;

/** The MariaDB account a copy's import signs in as, so the stream can do only what that account can on the target. */
final readonly class ImportAccount
{
    /** What a copied view, trigger or routine runs its own database with as its definer: read and write, call, fire. */
    public const OBJECTS_NEED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'EXECUTE', 'TRIGGER'];

    /** An empty $user asks sites-db for an account of its own, made and dropped around the import. */
    public function __construct(public string $user) {}

    /** For a database no account of its project is granted on. */
    public static function temporary(): self
    {
        return new self('');
    }

    /**
     * Of the accounts granted on $database, the one holding OBJECTS_NEED there, else the closest to it, so the objects
     * it is given keep working: the most privileged, then the one named like $database, then the first by name.
     *
     * @param  array<string, string>  $granted  account => its privileges on $database, as SHOW GRANTS lists them
     */
    public static function choose(string $database, array $granted): self
    {
        if ($granted === []) {
            return self::temporary();
        }

        $accounts = array_map('strval', array_keys($granted));
        usort($accounts, static fn (string $a, string $b): int => self::rank($b, $database, $granted[$b])
            <=> self::rank($a, $database, $granted[$a]) ?: strcmp($a, $b));

        return new self($accounts[0]);
    }

    /** @return array{0: bool, 1: int, 2: bool} */
    private static function rank(string $account, string $database, string $privileges): array
    {
        $held = self::held($privileges);

        return [
            $held === null || array_diff(self::OBJECTS_NEED, $held) === [],
            $held === null ? PHP_INT_MAX : count($held),
            $account === $database,
        ];
    }

    /** @return list<string>|null null for ALL PRIVILEGES */
    private static function held(string $privileges): ?array
    {
        $held = array_map(static fn (string $privilege): string => strtoupper(trim($privilege)), explode(',', $privileges));

        return array_intersect($held, ['ALL', 'ALL PRIVILEGES']) === [] ? $held : null;
    }
}
