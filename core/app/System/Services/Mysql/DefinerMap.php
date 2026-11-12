<?php

namespace App\System\Services\Mysql;

use App\Models\User;

/**
 * The DEFINER of every routine, trigger, view and event a root-run import recreates: a mapped source user becomes the
 * destination's, anything else an uncreatable account under the destination prefix, so it never runs as root.
 */
final class DefinerMap
{
    private const string ORPHAN = 'no.definer';

    private const string NAME_GRAMMAR = '/^[A-Za-z0-9_-]+$/D';

    /**
     * @param  array<string, string>  $accounts  source user => destination user
     */
    private function __construct(
        private readonly array $accounts,
        private readonly string $orphan,
    ) {}

    /**
     * Between two projects as they are now: only users the destination owns.
     */
    public static function between(User $source, User $dest): self
    {
        return self::map(
            $source->getMysqlPrefix(),
            $dest->getMysqlPrefix(),
            self::userNames($source),
            self::userNames($dest),
        );
    }

    /**
     * For a whole-project copy, which creates every source user under the
     * destination prefix once the databases are in.
     */
    public static function forProjectCopy(User $source, User $dest): self
    {
        $fromPrefix = $source->getMysqlPrefix();
        $toPrefix = $dest->getMysqlPrefix();
        $sourceUsers = self::userNames($source);
        $copied = array_map(
            static fn (string $user): string => DatabaseCopier::mapName($user, $fromPrefix, $toPrefix),
            $sourceUsers,
        );

        return self::map($fromPrefix, $toPrefix, $sourceUsers, $copied);
    }

    /**
     * @param  list<string>  $sourceUsers
     * @param  list<string>  $destUsers
     */
    public static function map(string $fromPrefix, string $toPrefix, array $sourceUsers, array $destUsers): self
    {
        $accounts = [];
        foreach ($sourceUsers as $user) {
            $mapped = DatabaseCopier::mapName($user, $fromPrefix, $toPrefix);
            if (self::isPlainName($user) && self::isPlainName($mapped) && in_array($mapped, $destUsers, true)) {
                $accounts[$user] = $mapped;
            }
        }

        return new self($accounts, self::orphanFor($toPrefix));
    }

    public function orphan(): string
    {
        return $this->orphan;
    }

    /**
     * @return array<string, string> source user => destination user
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    private static function orphanFor(string $prefix): string
    {
        if ($prefix !== '' && ! self::isPlainName($prefix)) {
            throw new \RuntimeException('Invalid MySQL prefix');
        }

        return $prefix.self::ORPHAN;
    }

    private static function isPlainName(string $name): bool
    {
        return preg_match(self::NAME_GRAMMAR, $name) === 1;
    }

    /**
     * @return list<string>
     */
    private static function userNames(User $user): array
    {
        /** @var list<string> $names */
        $names = $user->mysqlUsers()->pluck('user')->values()->all();

        return $names;
    }
}
