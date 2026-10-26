<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\MysqlDatabase;
use App\Models\User;

/**
 * A WordPress in the project's own containers moved by a staging, clone or
 * push. Its database comes with the copied volumes and is named per project,
 * so nothing is rewired: the copy only has to answer as its own domain rather
 * than redirect to the source's. A database another project owns on the
 * account MySQL server is never rewritten, since that would move the other
 * project to the copy's address. Every step runs without plugins and themes.
 */
final class ContainerSiteCopy
{
    /** @var \Closure(User): WordPress */
    private \Closure $wordPress;

    /** @var \Closure(string): ?int */
    private \Closure $databaseOwner;

    /**
     * @param  (callable(User): WordPress)|null  $wordPress
     * @param  (callable(string): ?int)|null  $databaseOwner  the id of the project owning a database, if any
     */
    public function __construct(?callable $wordPress = null, ?callable $databaseOwner = null)
    {
        $this->wordPress = \Closure::fromCallable(
            $wordPress ?? static fn (User $user): WordPress => WordPress::for($user, skipPlugins: true),
        );
        $this->databaseOwner = \Closure::fromCallable($databaseOwner ?? self::ownerInEngine(...));
    }

    /**
     * Before the copy, while the source runs: the hosts its WordPress answers
     * on, with the project's domain. None when its containers hold no
     * installed WordPress.
     *
     * @return list<string>
     */
    public function sourceHosts(User $source): array
    {
        $wordPress = ($this->wordPress)($source);
        if (! self::isInstalled($wordPress)) {
            return [];
        }

        return array_values(array_unique(array_filter([(string) $source->domain, ...$this->hosts($wordPress)])));
    }

    /**
     * Once the copy runs: its database answers as its domain. False when the
     * copy uses a database another project owns, which is left untouched.
     *
     * @param  list<string>  $sourceHosts
     */
    public function pointCopyAtItself(User $copy, array $sourceHosts): bool
    {
        $wordPress = ($this->wordPress)($copy);
        if ($this->usesAnotherProjectsDatabase($wordPress, $copy)) {
            return false;
        }
        (new HostRewrite($wordPress))->run($sourceHosts, (string) $copy->domain);
        $wordPress->cache()->flush();

        return true;
    }

    private function usesAnotherProjectsDatabase(WordPress $wordPress, User $copy): bool
    {
        $owner = ($this->databaseOwner)((string) $wordPress->config()->get('DB_NAME')['value']);

        return $owner !== null && $owner !== (int) $copy->id;
    }

    /** @return list<string> the hosts of home and siteurl */
    private function hosts(WordPress $wordPress): array
    {
        $hosts = [];
        foreach (['home', 'siteurl'] as $option) {
            $hosts[] = (string) parse_url($wordPress->options()->get($option)['value'], PHP_URL_HOST);
        }

        return array_values(array_filter($hosts));
    }

    private static function isInstalled(WordPress $wordPress): bool
    {
        try {
            return ($wordPress->cli()->run(['core', 'is-installed'])['exit_code'] ?? 1) === 0;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function ownerInEngine(string $database): ?int
    {
        $owner = MysqlDatabase::query()->where('database', $database)->value('user_id');

        return $owner === null ? null : (int) $owner;
    }
}
