<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Services\Mysql\DatabaseCopyResult;
use Mockery;
use PHPUnit\Framework\TestCase;

/** A classic WordPress multisite after a staging, copy or push: its main site answers on the copy's host. */
class SiteCopyMultisiteTest extends TestCase
{
    private const SELECT_SITE_URL = "SELECT meta_value FROM `wp_sitemeta` WHERE site_id = 1 AND meta_key = 'siteurl'";

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_subdomain_multisite_copy_moves_its_main_site_to_the_copy_host(): void
    {
        $copy = $this->multisite(['SUBDOMAIN_INSTALL' => true, 'SITE_ID_CURRENT_SITE' => 1, 'BLOG_ID_CURRENT_SITE' => 1]);
        $copy->networkSiteUrl = 'https://www.shop.example/';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame([
            self::SELECT_SITE_URL,
            "UPDATE `wp_site` SET domain = 'staging.shop.test' WHERE id = 1; "
            . "UPDATE `wp_blogs` SET domain = 'staging.shop.test' WHERE blog_id = 1; "
            . "UPDATE `wp_sitemeta` SET meta_value = 'https://staging.shop.test/' WHERE site_id = 1 AND meta_key = 'siteurl';",
        ], $copy->queries);
        $this->assertContains(
            ['db', 'query', self::SELECT_SITE_URL, '--skip-column-names', '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html'],
            $copy->calls,
        );
        $this->assertSame('staging.shop.test', $copy->constants['DOMAIN_CURRENT_SITE']);
        $this->assertSame('/', $copy->constants['PATH_CURRENT_SITE']);
        $this->assertSame('https://staging.shop.test', $copy->options['home']);
    }

    public function test_a_subdirectory_multisite_copy_keeps_the_network_path_and_reads_its_table_prefix(): void
    {
        $copy = $this->multisite(['SUBDOMAIN_INSTALL' => false, 'PATH_CURRENT_SITE' => '/network/'], 'net_');
        $copy->networkSiteUrl = 'http://www.shop.example/network/';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame([
            "SELECT meta_value FROM `net_sitemeta` WHERE site_id = 1 AND meta_key = 'siteurl'",
            "UPDATE `net_site` SET domain = 'staging.shop.test' WHERE id = 1; "
            . "UPDATE `net_blogs` SET domain = 'staging.shop.test' WHERE blog_id = 1; "
            . "UPDATE `net_sitemeta` SET meta_value = 'http://staging.shop.test/network/' WHERE site_id = 1 AND meta_key = 'siteurl';",
        ], $copy->queries);
        $this->assertSame('staging.shop.test', $copy->constants['DOMAIN_CURRENT_SITE']);
        $this->assertSame('/network/', $copy->constants['PATH_CURRENT_SITE']);
    }

    public function test_a_multisite_copy_boots_wordpress_on_the_source_host_until_the_tables_and_constant_move(): void
    {
        $copy = $this->multisite(['SUBDOMAIN_INSTALL' => true]);
        $copy->networkSiteUrl = 'https://www.shop.example/';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $searchReplace = $this->lastCallIndex($copy, 'search-replace');
        $tables = $this->lastCallIndex($copy, 'db');
        $constant = array_search(
            ['config', 'set', 'DOMAIN_CURRENT_SITE', 'staging.shop.test', '--type=constant', '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html'],
            $copy->calls,
            true,
        );
        $this->assertLessThan($tables, $searchReplace);
        $this->assertLessThan($constant, $tables);
    }

    public function test_a_multisite_without_a_network_site_url_row_moves_only_its_domains(): void
    {
        $copy = $this->multisite([]);

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame(
            "UPDATE `wp_site` SET domain = 'staging.shop.test' WHERE id = 1; "
            . "UPDATE `wp_blogs` SET domain = 'staging.shop.test' WHERE blog_id = 1;",
            $copy->queries[1],
        );
    }

    public function test_a_single_site_copy_runs_nothing_new(): void
    {
        $copy = $this->site(['MULTISITE' => false, 'DOMAIN_CURRENT_SITE' => 'www.shop.example']);

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame([], $copy->queries);
        $this->assertSame('www.shop.example', $copy->constants['DOMAIN_CURRENT_SITE']);
        $this->assertSame([
            'config get DB_NAME',
            'config get DB_USER',
            'config set DB_NAME stg_wp',
            'config set DB_USER stg_wpuser',
            'config set DB_PASSWORD fresh-secret',
            'option get home',
            'option get siteurl',
            'search-replace (//|\\\\/\\\\/)www\.shop\.example(?![A-Za-z0-9.-]) ${1}staging.shop.test',
            'config list',
            'cache flush',
        ], array_map(self::commandWords(...), $copy->calls));
    }

    public function test_a_multisite_push_points_the_tables_at_the_target_before_wordpress_boots(): void
    {
        $live = $this->multisite(['SUBDOMAIN_INSTALL' => false], constants: ['DOMAIN_CURRENT_SITE' => 'shop.example']);
        $staging = $this->multisite(['SUBDOMAIN_INSTALL' => false], constants: ['DOMAIN_CURRENT_SITE' => 'staging.shop.test']);
        $staging->options = ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0'];
        $live->options['home'] = 'https://shop.example';
        $live->options['siteurl'] = 'https://shop.example';
        $siteCopy = $this->siteCopy(['live' => $live, 'stg' => $staging]);
        $kept = $siteCopy->beforePush($this->user('stg', 'staging.shop.test'), $this->user('live', 'shop.example'), '/home/live/wp-config.php');
        $live->options = $staging->options;
        $live->networkSiteUrl = 'https://staging.shop.test/';
        $live->calls = [];

        $siteCopy->afterPushedDatabase($this->user('live', 'shop.example'), $kept);

        $this->assertSame(['config', 'list'], array_slice($live->calls[0], 0, 2));
        $this->assertSame(['db', 'query'], array_slice($live->calls[1], 0, 2));
        $this->assertSame(['db', 'query'], array_slice($live->calls[2], 0, 2));
        $this->assertSame(
            "UPDATE `wp_site` SET domain = 'shop.example' WHERE id = 1; "
            . "UPDATE `wp_blogs` SET domain = 'shop.example' WHERE blog_id = 1; "
            . "UPDATE `wp_sitemeta` SET meta_value = 'https://shop.example/' WHERE site_id = 1 AND meta_key = 'siteurl';",
            $live->queries[1],
        );
        $this->assertSame('search-replace', $live->calls[3][0]);
        $this->assertSame('shop.example', $live->constants['DOMAIN_CURRENT_SITE']);
        $this->assertSame([], array_filter($live->calls, static fn (array $args): bool => $args[0] === 'config' && $args[1] === 'set'));
        $this->assertSame('https://shop.example', $live->options['home']);
    }

    public function test_a_single_site_push_runs_no_query(): void
    {
        $live = $this->site([]);
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0']);
        $siteCopy = $this->siteCopy(['live' => $live, 'stg' => $staging]);
        $kept = $siteCopy->beforePush($this->user('stg', 'staging.shop.test'), $this->user('live', 'shop.example'), '/home/live/wp-config.php');
        $live->options = $staging->options;

        $siteCopy->afterPushedDatabase($this->user('live', 'shop.example'), $kept);

        $this->assertSame([], $live->queries);
        $this->assertSame('https://www.shop.example', $live->options['home']);
    }

    public function test_a_table_prefix_that_is_not_an_identifier_is_refused_before_any_query(): void
    {
        $copy = $this->multisite([], "wp_'; DROP TABLE wp_users; --");

        try {
            $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());
            $this->fail('the prefix should have been refused');
        } catch (WordPressException $e) {
            $this->assertStringContainsString('table prefix', $e->getMessage());
        }

        $this->assertSame([], $copy->queries);
    }

    /**
     * @param array<string, mixed> $network
     * @param array<string, mixed> $constants
     */
    private function multisite(array $network, string $prefix = 'wp_', array $constants = []): FakeWordPressSite
    {
        $site = $this->site([
            'MULTISITE' => true,
            'DOMAIN_CURRENT_SITE' => 'www.shop.example',
            'PATH_CURRENT_SITE' => '/',
            ...$network,
            ...$constants,
        ]);
        $site->variables = ['table_prefix' => $prefix];

        return $site;
    }

    /** @param array<string, mixed> $constants */
    private function site(array $constants): FakeWordPressSite
    {
        return new FakeWordPressSite(
            ['DB_NAME' => 'live_wp', 'DB_USER' => 'live_wpuser', 'DB_PASSWORD' => 'live-secret', ...$constants],
            ['home' => 'https://www.shop.example', 'siteurl' => 'https://www.shop.example', 'blog_public' => '1'],
        );
    }

    /** @param list<string> $args */
    private static function commandWords(array $args): string
    {
        return implode(' ', array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));
    }

    private function lastCallIndex(FakeWordPressSite $site, string $command): int
    {
        $found = array_keys(array_filter($site->calls, static fn (array $args): bool => $args[0] === $command));

        return (int) end($found);
    }

    private function copiedDatabases(): DatabaseCopyResult
    {
        return new DatabaseCopyResult(
            ['live_wp' => 'stg_wp'],
            ['live_wpuser' => 'stg_wpuser'],
            ['stg_wpuser' => 'fresh-secret'],
        );
    }

    /** @param array<string, FakeWordPressSite> $sites username => site */
    private function siteCopy(array $sites): SiteCopy
    {
        return new SiteCopy(static fn (User $user) => $sites[$user->username]->wordPress());
    }

    private function user(string $username, string $domain): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $domain;

        return $user;
    }
}
