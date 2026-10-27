<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System\Services\Mysql\DatabaseCopyResult;

/**
 * A classic WordPress moved by a staging, copy or push. The files and the
 * database move as they are, so without this a copy keeps using the source's
 * database and answering as the source's domain, and a push leaves the target
 * on the source's wp-config.php and addresses. Every step reads wp-config.php
 * or runs without plugins and themes, so a broken plugin cannot stop it.
 * On a multisite the main site moves with every site on its host, which in
 * a subdirectory network is every site; subsites on their own hosts stay.
 */
final class SiteCopy
{
    private const URL_CONSTANTS = ['WP_HOME', 'WP_SITEURL'];

    /** @var \Closure(User): WordPress */
    private \Closure $wordPress;

    /** @param (callable(User): WordPress)|null $wordPress */
    public function __construct(?callable $wordPress = null)
    {
        $this->wordPress = \Closure::fromCallable(
            $wordPress ?? static fn (User $user): WordPress => WordPress::for($user, skipPlugins: true),
        );
    }

    /**
     * After a staging or copy: the copy's wp-config.php uses the database
     * copied for it, and the site answers as the copy's domain.
     */
    public function pointCopyAtItself(User $copy, ?DatabaseCopyResult $databases): void
    {
        $wordPress = ($this->wordPress)($copy);
        if ($databases === null) {
            throw new Exception('A WordPress copy needs its own databases; without them it would use the source\'s.');
        }

        $this->useCopiedDatabase($wordPress->config(), $databases);
        $this->answerAs($wordPress, $this->hosts($wordPress), (string) $copy->domain);
        $wordPress->cache()->flush();
    }

    /** Before a push: what the target, whose wp-config.php is at the path given, keeps. */
    public function beforePush(User $source, User $target, string $targetConfigPath): KeptSite
    {
        $options = ($this->wordPress)($target)->options();

        return new KeptSite(
            $targetConfigPath,
            $options->get('home')['value'],
            $options->get('siteurl')['value'],
            $options->get('blog_public')['value'],
            $this->hosts(($this->wordPress)($source)),
        );
    }

    /**
     * After a pushed database: the target answers on its own addresses again.
     * A multisite target's own wp-config.php names its host while the pushed
     * network tables name the source's, and WordPress cannot boot until they
     * agree, so the tables are rewritten first, before anything loads it. A
     * push of some tables rewrites only those, as the target holds them.
     *
     * @param  list<array{database: string, name: string}>|null  $tables
     */
    public function afterPushedDatabase(User $target, KeptSite $kept, ?array $tables = null): void
    {
        $wordPress = ($this->wordPress)($target);
        $this->pointMultisiteTablesAtTarget($wordPress, $kept);
        HostRewrite::ofPushedTables($wordPress, $tables)->run($kept->sourceHosts, $kept->host());
        $options = $wordPress->options();
        $options->set('home', $kept->home);
        $options->set('siteurl', $kept->siteUrl);
        $options->set('blog_public', $kept->blogPublic);
        $wordPress->cache()->flush();
    }

    private function useCopiedDatabase(Config $config, DatabaseCopyResult $databases): void
    {
        $name = (string) $config->get('DB_NAME')['value'];
        $user = (string) $config->get('DB_USER')['value'];
        $copiedName = $databases->databases[$name] ?? null;
        $copiedUser = $databases->users[$user] ?? null;
        $password = $copiedUser === null ? null : ($databases->passwords[$copiedUser] ?? null);
        if ($copiedName === null || $copiedUser === null || $password === null) {
            throw new Exception(
                "WordPress uses database '{$name}' as '{$user}', which the project does not own, so the copy "
                .'would keep using it.'
            );
        }

        $config->set('DB_NAME', $copiedName);
        $config->set('DB_USER', $copiedUser);
        $config->set('DB_PASSWORD', $password);
    }

    private function pointMultisiteTablesAtTarget(WordPress $wordPress, KeptSite $kept): void
    {
        $multisite = MultisiteMainSite::fromConfig($wordPress->config()->list('all'));
        $multisite?->pointTablesAt($wordPress->cli(), $multisite->domain() ?? $kept->host());
    }

    /**
     * The search-replace boots WordPress, which on a multisite finds its main
     * site by DOMAIN_CURRENT_SITE in the network tables, so it runs while both
     * still name the source's host. The network tables and DOMAIN_CURRENT_SITE
     * follow, then the URL constants.
     *
     * @param  list<string>  $hosts
     */
    private function answerAs(WordPress $wordPress, array $hosts, string $domain): void
    {
        (new HostRewrite($wordPress))->run($hosts, $domain);
        $config = $wordPress->config()->list('all');
        MultisiteMainSite::fromConfig($config)?->pointAt($wordPress, $domain);
        foreach ($this->urlConstants($config) as $name => $url) {
            $wordPress->config()->set($name, self::withHost($url, $domain));
        }
    }

    /**
     * The hosts WordPress answers on, from home and siteurl (or the WP_HOME
     * and WP_SITEURL constants that override them).
     *
     * @return list<string>
     */
    private function hosts(WordPress $wordPress): array
    {
        $hosts = [];
        foreach (['home', 'siteurl'] as $option) {
            $host = (string) parse_url($wordPress->options()->get($option)['value'], PHP_URL_HOST);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  list<array<string, mixed>>  $configRows
     * @return array<string, string> constant => URL, for the URL constants wp-config.php defines
     */
    private function urlConstants(array $configRows): array
    {
        $defined = [];
        foreach ($configRows as $row) {
            $name = $row['name'] ?? $row['key'] ?? null;
            if (in_array($name, self::URL_CONSTANTS, true) && is_string($row['value'] ?? null)) {
                $defined[$name] = $row['value'];
            }
        }

        return $defined;
    }

    public static function withHost(string $url, string $host): string
    {
        return (string) preg_replace('~^([a-z][a-z0-9+.-]*://)[^/:?#]+~i', '${1}'.$host, $url);
    }
}
