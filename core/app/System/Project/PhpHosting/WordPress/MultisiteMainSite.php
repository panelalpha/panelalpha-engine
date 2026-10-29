<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * The main site of a classic WordPress multisite (MULTISITE in wp-config.php)
 * moved to another host. WordPress finds the network and its main site by the
 * request's host in {prefix}site and {prefix}blogs, and network URLs come from
 * the siteurl row of {prefix}sitemeta, so a copy that keeps the source's host
 * there redirects to the source or answers "site not found". Every network
 * and site on the main site's host moves with it, which in a subdirectory
 * network is every site; subsites on hosts of their own keep them. The
 * tables change through `wp db query`, which reads
 * wp-config.php without loading WordPress, so it runs whatever host WordPress
 * would boot on.
 */
final class MultisiteMainSite
{
    public const DOMAIN_CONSTANT = 'DOMAIN_CURRENT_SITE';

    private function __construct(
        private string $tablePrefix,
        private int $networkId,
        private int $mainSiteId,
        private ?string $domain,
    ) {}

    /**
     * The multisite wp-config.php describes, or null for a single site.
     *
     * @param  list<array<string, mixed>>  $configRows  Config::list('all')
     */
    public static function fromConfig(array $configRows): ?self
    {
        $values = self::valuesByName($configRows);
        if (! filter_var($values['MULTISITE'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return new self(
            self::tablePrefix($values['table_prefix'] ?? 'wp_'),
            self::id($values['SITE_ID_CURRENT_SITE'] ?? 1),
            self::id($values['BLOG_ID_CURRENT_SITE'] ?? 1),
            is_string($values[self::DOMAIN_CONSTANT] ?? null) ? $values[self::DOMAIN_CONSTANT] : null,
        );
    }

    /** The host DOMAIN_CURRENT_SITE names, when wp-config.php defines it. */
    public function domain(): ?string
    {
        return $this->domain;
    }

    /**
     * The network tables first, then DOMAIN_CURRENT_SITE (a bare host, no
     * scheme), so the next WP-CLI run boots on the new host with tables that
     * agree with it.
     */
    public function pointAt(WordPress $wordPress, string $host): void
    {
        $this->pointTablesAt($wordPress->cli(), $host);
        if ($this->domain !== null) {
            $wordPress->config()->set(self::DOMAIN_CONSTANT, $host);
        }
    }

    /** The main site's host, and every row on it, in the network tables; the siteurl row keeps its scheme and path. */
    public function pointTablesAt(WpCli $cli, string $host): void
    {
        $onMainSiteHost = $this->onMainSiteHost($cli);
        $statements = [
            sprintf('UPDATE `%s` SET domain = %s WHERE id = %d%s', $this->table('site'), self::quoted($host), $this->networkId, $onMainSiteHost),
            sprintf('UPDATE `%s` SET domain = %s WHERE blog_id = %d%s', $this->table('blogs'), self::quoted($host), $this->mainSiteId, $onMainSiteHost),
            ...$this->siteUrlUpdate($cli, $host),
        ];
        $cli->runOrFail(['db', 'query', implode('; ', $statements).';']);
    }

    /**
     * The rows still on the main site's host move with it, as the host
     * rewrite moves every address on that host.
     */
    private function onMainSiteHost(WpCli $cli): string
    {
        $host = $this->firstValue($cli, sprintf(
            'SELECT domain FROM `%s` WHERE blog_id = %d',
            $this->table('blogs'),
            $this->mainSiteId,
        ));

        return $host === '' ? '' : ' OR domain = '.self::quoted($host);
    }

    /** @return list<string> */
    private function siteUrlUpdate(WpCli $cli, string $host): array
    {
        $siteUrl = $this->networkSiteUrl($cli);
        if ($siteUrl === '') {
            return [];
        }

        return [sprintf(
            "UPDATE `%s` SET meta_value = %s WHERE site_id = %d AND meta_key = 'siteurl'",
            $this->table('sitemeta'),
            self::quoted(SiteCopy::withHost($siteUrl, $host)),
            $this->networkId,
        )];
    }

    private function networkSiteUrl(WpCli $cli): string
    {
        return $this->firstValue($cli, sprintf(
            "SELECT meta_value FROM `%s` WHERE site_id = %d AND meta_key = 'siteurl'",
            $this->table('sitemeta'),
            $this->networkId,
        ));
    }

    private function firstValue(WpCli $cli, string $select): string
    {
        $result = $cli->runOrFail(['db', 'query', $select, '--skip-column-names']);

        return trim(explode("\n", trim($result['stdout']))[0]);
    }

    private function table(string $name): string
    {
        return $this->tablePrefix.$name;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function valuesByName(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? $row['key'] ?? null;
            if (is_string($name)) {
                $values[$name] = $row['value'] ?? null;
            }
        }

        return $values;
    }

    private static function tablePrefix(mixed $prefix): string
    {
        if (! is_string($prefix) || preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new Exception('The multisite table prefix in wp-config.php is not letters, digits and underscores.');
        }

        return $prefix;
    }

    private static function id(mixed $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new Exception('The multisite network or main site ID in wp-config.php is not a positive number.');
        }

        return $value;
    }

    private static function quoted(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
