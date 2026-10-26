<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\ContainerSiteCopy;
use Mockery;
use PHPUnit\Framework\TestCase;

/** A WordPress in the project's own containers after a staging, clone or push answers as its own domain. */
class ContainerSiteCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_source_hosts_are_its_domain_and_the_hosts_its_wordpress_answers_on(): void
    {
        $live = $this->site('https://www.shop.example', 'https://www.shop.example/wp');

        $hosts = $this->containerSiteCopy(['live' => $live])->sourceHosts($this->user('live', 'shop.example'));

        $this->assertSame(['shop.example', 'www.shop.example'], $hosts);
    }

    public function test_a_source_without_an_installed_wordpress_has_no_hosts(): void
    {
        $live = $this->site('https://shop.example', 'https://shop.example');
        $live->installed = false;

        $this->assertSame([], $this->containerSiteCopy(['live' => $live])->sourceHosts($this->user('live', 'shop.example')));
        $this->assertSame([['core', 'is-installed']], array_map(static fn (array $args): array => array_slice($args, 0, 2), $live->calls));
    }

    public function test_a_source_whose_containers_hold_no_wordpress_has_no_hosts(): void
    {
        $live = $this->site('https://shop.example', 'https://shop.example');
        $live->installed = new \RuntimeException('No WordPress install was found in this project\'s containers.');

        $this->assertSame([], $this->containerSiteCopy(['live' => $live])->sourceHosts($this->user('live', 'shop.example')));
    }

    public function test_a_staging_answers_as_its_own_domain_and_flushes_its_cache(): void
    {
        $staging = $this->site('https://shop.example', 'https://shop.example');
        $staging->options['about'] = 'https://shop.example/about https://www.shop.example/ https://shop.example.au/';

        $rewritten = $this->containerSiteCopy(['stg' => $staging])
            ->pointCopyAtItself($this->user('stg', 'staging.shop.test', 7), ['shop.example', 'www.shop.example']);

        $this->assertTrue($rewritten);
        $this->assertSame('https://staging.shop.test', $staging->options['home']);
        $this->assertSame(
            'https://staging.shop.test/about https://staging.shop.test/ https://shop.example.au/',
            $staging->options['about'],
        );
        $this->assertSame([
            '(//|\\\\/\\\\/)shop\\.example(?![A-Za-z0-9.-])',
            '(//|\\\\/\\\\/)www\\.shop\\.example(?![A-Za-z0-9.-])',
        ], array_column($staging->searchReplaces(), 1));
        $this->assertSame(['cache', 'flush'], array_slice((array) end($staging->calls), 0, 2));
        $this->assertSame([], array_filter($staging->calls, static fn (array $args): bool => $args[1] === 'set'));
    }

    public function test_a_push_back_keeps_lives_address(): void
    {
        $live = $this->site('https://staging.shop.test', 'https://staging.shop.test');

        $this->containerSiteCopy(['live' => $live])->pointCopyAtItself($this->user('live', 'shop.example', 3), ['staging.shop.test']);

        $this->assertSame('https://shop.example', $live->options['home']);
        $this->assertSame('https://shop.example', $live->options['siteurl']);
    }

    public function test_a_copy_on_a_database_another_project_owns_is_left_untouched(): void
    {
        $staging = $this->site('https://shop.example', 'https://shop.example');

        $rewritten = $this->containerSiteCopy(['stg' => $staging], owner: 3)
            ->pointCopyAtItself($this->user('stg', 'staging.shop.test', 7), ['shop.example']);

        $this->assertFalse($rewritten);
        $this->assertSame('https://shop.example', $staging->options['home']);
        $this->assertSame([], $staging->searchReplaces());
    }

    public function test_a_copy_on_its_own_account_database_is_rewritten(): void
    {
        $staging = $this->site('https://shop.example', 'https://shop.example');

        $rewritten = $this->containerSiteCopy(['stg' => $staging], owner: 7)
            ->pointCopyAtItself($this->user('stg', 'staging.shop.test', 7), ['shop.example']);

        $this->assertTrue($rewritten);
        $this->assertSame('https://staging.shop.test', $staging->options['home']);
    }

    private function site(string $home, string $siteUrl): FakeWordPressSite
    {
        return new FakeWordPressSite(['DB_NAME' => 'wordpress'], ['home' => $home, 'siteurl' => $siteUrl]);
    }

    /** @param array<string, FakeWordPressSite> $sites username => site */
    private function containerSiteCopy(array $sites, ?int $owner = null): ContainerSiteCopy
    {
        return new ContainerSiteCopy(
            static fn (User $user) => $sites[$user->username]->wordPress(),
            static fn (string $database): ?int => $database === 'wordpress' ? $owner : null,
        );
    }

    private function user(string $username, string $domain, int $id = 1): User
    {
        $user = new User();
        $user->id = $id;
        $user->username = $username;
        $user->domain = $domain;

        return $user;
    }
}
