<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Install;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Services\Mysql\DatabaseCopyResult;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWordPressSite;

/** A classic WordPress after a staging, copy or push: its own database and its own addresses. */
class SiteCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_copy_uses_its_copied_database_and_answers_as_its_domain(): void
    {
        $copy = $this->liveSite();
        $copy->constants['WP_HOME'] = 'https://www.shop.example';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame([
            'DB_NAME' => 'stg_wp',
            'DB_USER' => 'stg_wpuser',
            'DB_PASSWORD' => 'fresh-secret',
            'WP_HOME' => 'https://staging.shop.test',
        ], $copy->constants);
        $this->assertSame([
            'home' => 'https://staging.shop.test',
            'siteurl' => 'https://staging.shop.test/wp',
            'blog_public' => '1',
            'about' => 'Visit https://staging.shop.test/about or https://staging.shop.test/wp/feed',
        ], $copy->options);
        foreach ($copy->calls as $args) {
            $this->assertContains('--skip-plugins', $args, 'a broken plugin cannot stop the copy');
        }
    }

    public function test_a_copy_whose_database_the_project_does_not_own_is_refused_untouched(): void
    {
        $copy = $this->liveSite();
        $copy->constants['DB_NAME'] = 'someone_else';

        try {
            $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());
            $this->fail('the copy should have been refused');
        } catch (WordPressException $e) {
            $this->assertStringContainsString("database 'someone_else'", $e->getMessage());
        }

        $this->assertSame('live_wpuser', $copy->constants['DB_USER']);
        $this->assertSame('https://www.shop.example', $copy->options['home']);
    }

    public function test_a_copy_without_its_databases_is_refused(): void
    {
        $this->expectException(WordPressException::class);

        $this->siteCopy(['stg' => $this->liveSite()])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), null);
    }

    public function test_after_a_pushed_database_the_target_answers_on_its_own_addresses(): void
    {
        $live = $this->liveSite();
        $staging = new FakeWordPressSite([], [
            'home' => 'https://staging.shop.test',
            'siteurl' => 'https://staging.shop.test/wp',
            'blog_public' => '0',
            'about' => 'Visit https://staging.shop.test/about',
        ]);
        $siteCopy = $this->siteCopy(['live' => $live, 'stg' => $staging]);
        $kept = $siteCopy->beforePush($this->user('stg', 'staging.shop.test'), $this->user('live', 'shop.example'), new Install('/home/live', '/home/live/wp-config.php'), new Install('/home/stg', '/home/stg/wp-config.php'));
        $live->options = $staging->options;

        $siteCopy->afterPushedDatabase($this->user('live', 'shop.example'), $kept);

        $this->assertSame([
            'home' => 'https://www.shop.example',
            'siteurl' => 'https://www.shop.example/wp',
            'blog_public' => '1',
            'about' => 'Visit https://www.shop.example/about',
        ], $live->options);
        $this->assertSame('/home/live/wp-config.php', $kept->install->configPath);
    }

    public function test_a_copy_rewrites_only_addresses_on_the_source_host_then_flushes_the_cache(): void
    {
        $copy = $this->liveSite();
        $copy->options['links'] = 'https://www.shop.example/page https:\/\/www.shop.example\/feed '
            . 'https://www.shop.example.au/ https://www.shop.example-two.test/ https://awww.shop.example/ info@www.shop.example';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself($this->user('stg', 'staging.shop.test'), $this->copiedDatabases());

        $this->assertSame(
            'https://staging.shop.test/page https:\/\/staging.shop.test\/feed '
            . 'https://www.shop.example.au/ https://www.shop.example-two.test/ https://awww.shop.example/ info@www.shop.example',
            $copy->options['links'],
        );
        $this->assertSame([[
            'search-replace', '(//|\\\\/\\\\/)www\\.shop\\.example(?![A-Za-z0-9.-])', '${1}staging.shop.test',
            '--regex', '--regex-delimiter=~', '--all-tables-with-prefix', '--precise', '--skip-columns=guid',
            '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html',
        ]], $copy->searchReplaces());
        $this->assertSame(['cache', 'flush'], array_slice((array) end($copy->calls), 0, 2));
    }

    public function test_a_table_push_rewrites_only_the_pushed_wordpress_tables(): void
    {
        $live = $this->liveSite();
        $live->constants['table_prefix'] = 'wp_';
        $staging = new FakeWordPressSite([], ['home' => 'https://staging.shop.test', 'siteurl' => 'https://staging.shop.test', 'blog_public' => '0']);
        $siteCopy = $this->siteCopy(['live' => $live, 'stg' => $staging]);
        $kept = $siteCopy->beforePush($this->user('stg', 'staging.shop.test'), $this->user('live', 'shop.example'), new Install('/home/live', '/home/live/wp-config.php'), new Install('/home/stg', '/home/stg/wp-config.php'));

        $siteCopy->afterPushedDatabase($this->user('live', 'shop.example'), $kept, [
            ['database' => 'live_wp', 'name' => 'wp_posts'],
            ['database' => 'live_crm', 'name' => 'wp_posts'],
        ]);

        $this->assertSame(['wp_posts', '--regex'], array_slice($live->searchReplaces()[0], 3, 2));
        $this->assertSame(['cache', 'flush'], array_slice((array) end($live->calls), 0, 2));
    }

    public function test_a_subdirectory_copy_uses_its_own_database_and_addresses_at_the_install_directory(): void
    {
        $copy = $this->liveSite();
        $copy->options = [
            'home' => 'https://shop.example/blog',
            'siteurl' => 'https://shop.example/blog',
            'blog_public' => '1',
        ];
        $copy->constants['WP_HOME'] = 'https://shop.example/blog';
        $install = '/home/site/staging.shop.test/public_html/blog';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself(
            $this->user('stg', 'staging.shop.test'),
            $this->copiedDatabases(),
            new Install($install, $install.'/wp-config.php'),
        );

        $this->assertSame('stg_wp', $copy->constants['DB_NAME']);
        $this->assertSame('stg_wpuser', $copy->constants['DB_USER']);
        $this->assertSame('fresh-secret', $copy->constants['DB_PASSWORD']);
        $this->assertSame('https://staging.shop.test/blog', $copy->constants['WP_HOME']);
        $this->assertSame('https://staging.shop.test/blog', $copy->options['home']);
        $this->assertSame('https://staging.shop.test/blog', $copy->options['siteurl']);
        foreach ($copy->calls as $args) {
            $this->assertContains('--path='.$install, $args, 'WP-CLI reads the copied install, not the document root');
        }
    }

    public function test_a_root_install_copy_is_pointed_at_its_own_database_at_the_document_root(): void
    {
        $copy = $this->liveSite();
        $install = '/home/site/staging.shop.test/public_html';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself(
            $this->user('stg', 'staging.shop.test'),
            $this->copiedDatabases(),
            new Install($install, $install.'/wp-config.php'),
        );

        $this->assertSame('stg_wp', $copy->constants['DB_NAME']);
        $this->assertSame('https://staging.shop.test', $copy->options['home']);
        foreach ($copy->calls as $args) {
            $this->assertContains('--path='.$install, $args);
        }
    }

    public function test_a_copy_whose_wp_config_is_above_its_wordpress_runs_wp_cli_in_the_wordpress_directory(): void
    {
        $copy = $this->liveSite();
        $docroot = '/home/site/staging.shop.test/public_html';

        $this->siteCopy(['stg' => $copy])->pointCopyAtItself(
            $this->user('stg', 'staging.shop.test'),
            $this->copiedDatabases(),
            new Install($docroot.'/blog', $docroot.'/wp-config.php'),
        );

        $this->assertSame('stg_wp', $copy->constants['DB_NAME']);
        foreach ($copy->calls as $args) {
            $this->assertContains('--path='.$docroot.'/blog', $args);
        }
    }

    private function liveSite(): FakeWordPressSite
    {
        return new FakeWordPressSite(
            ['DB_NAME' => 'live_wp', 'DB_USER' => 'live_wpuser', 'DB_PASSWORD' => 'live-secret'],
            [
                'home' => 'https://www.shop.example',
                'siteurl' => 'https://www.shop.example/wp',
                'blog_public' => '1',
                'about' => 'Visit https://www.shop.example/about or https://www.shop.example/wp/feed',
            ],
        );
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
        return new SiteCopy(static fn (User $user, ?string $path = null) => $sites[$user->username]->wordPress($path));
    }

    private function user(string $username, string $domain): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $domain;

        return $user;
    }
}
