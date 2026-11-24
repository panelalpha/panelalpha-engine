<?php

namespace App\System\Services\Mysql;

use App\Models\User;

/** Source users mapped to the destination's users, for the grants a push gives on a database it creates. */
final class AccountMap
{
    private const string NAME_GRAMMAR = '/^[A-Za-z0-9_-]+$/D';

    /**
     * @param  array<string, string>  $accounts  source user => destination user
     */
    private function __construct(
        private readonly array $accounts,
    ) {}

    /** Between two projects as they are now: only users the destination owns. */
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

        return new self($accounts);
    }

    /**
     * @return array<string, string> source user => destination user
     */
    public function accounts(): array
    {
        return $this->accounts;
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
