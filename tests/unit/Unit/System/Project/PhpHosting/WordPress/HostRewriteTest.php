<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\System\Project\PhpHosting\WordPress\HostRewrite;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A host rewrite changes addresses on exactly that host and nothing that merely contains it. */
class HostRewriteTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function addressesOnTheHost(): array
    {
        return [
            'a page' => ['https://example.com/page'],
            'the bare host' => ['https://example.com'],
            'a protocol-relative URL' => ['<img src="//example.com/a.png">'],
            'a port' => ['http://example.com:8080/'],
            'a query' => ['https://example.com?p=1'],
            'a quoted URL' => ['href="https://example.com"'],
            'JSON-escaped' => ['{"url":"https:\/\/example.com\/page"}'],
            'serialized' => ['s:24:"https://example.com/page";'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function addressesOnOtherHosts(): array
    {
        return [
            'a longer TLD' => ['https://example.com.au/page'],
            'a longer name' => ['https://example.community/'],
            'a host ending in it' => ['https://anotherexample.com/'],
            'a subdomain' => ['https://www.example.com/'],
            'an e-mail address' => ['info@example.com'],
            'a hyphenated host' => ['https://example.com-shop.test/'],
            'JSON-escaped longer TLD' => ['https:\/\/example.com.au\/'],
            'no slashes' => ['example.com'],
        ];
    }

    #[DataProvider('addressesOnTheHost')]
    public function test_an_address_on_the_host_matches(string $text): void
    {
        $this->assertSame(1, preg_match($this->regex('example.com'), $text));
    }

    #[DataProvider('addressesOnOtherHosts')]
    public function test_an_address_on_another_host_does_not_match(string $text): void
    {
        $this->assertSame(0, preg_match($this->regex('example.com'), $text));
    }

    public function test_the_dot_in_the_host_is_literal(): void
    {
        $this->assertSame(0, preg_match($this->regex('example.com'), 'https://exampleXcom/'));
    }

    public function test_the_replacement_keeps_the_escaping_it_found(): void
    {
        $text = 'https://example.com/a https:\/\/example.com\/b info@example.com https://example.com.au/';

        $this->assertSame(
            'https://staging.example.com/a https:\/\/staging.example.com\/b info@example.com https://example.com.au/',
            preg_replace($this->regex('example.com'), HostRewrite::replacement('staging.example.com'), $text),
        );
    }

    public function test_a_replacement_host_starting_with_a_digit_is_not_read_as_a_group(): void
    {
        $this->assertSame(
            'https://1.example.test/',
            preg_replace($this->regex('example.com'), HostRewrite::replacement('1.example.test'), 'https://example.com/'),
        );
    }

    public function test_it_runs_one_precise_regex_search_replace_per_other_host(): void
    {
        $site = new FakeWordPressSite([], ['home' => 'https://example.com']);

        (new HostRewrite($site->wordPress()))->run(['example.com', 'www.example.com', 'new.test'], 'new.test');

        $this->assertSame([
            [
                'search-replace', '(//|\\\\/\\\\/)example\\.com(?![A-Za-z0-9.-])', '${1}new.test',
                '--regex', '--regex-delimiter=~', '--all-tables-with-prefix', '--precise', '--skip-columns=guid',
                '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html',
            ],
            [
                'search-replace', '(//|\\\\/\\\\/)www\\.example\\.com(?![A-Za-z0-9.-])', '${1}new.test',
                '--regex', '--regex-delimiter=~', '--all-tables-with-prefix', '--precise', '--skip-columns=guid',
                '--skip-plugins', '--skip-themes', '--path=/home/site/site.test/public_html',
            ],
        ], $site->searchReplaces());
    }

    public function test_pushed_tables_limit_it_to_the_wordpress_database_and_prefix(): void
    {
        $site = new FakeWordPressSite(['DB_NAME' => 'live_wp', 'table_prefix' => 'wp_'], ['home' => 'https://example.com']);

        HostRewrite::ofPushedTables($site->wordPress(), [
            ['database' => 'live_wp', 'name' => 'wp_posts'],
            ['database' => 'live_wp', 'name' => 'wp_yoast_indexable'],
            ['database' => 'live_wp', 'name' => 'legacy_log'],
            ['database' => 'live_shop', 'name' => 'wp_options'],
        ])->run(['staging.example.com'], 'example.com');

        $this->assertSame(
            ['wp_posts', 'wp_yoast_indexable', '--regex'],
            array_slice($site->searchReplaces()[0], 3, 3),
        );
    }

    public function test_pushed_tables_none_of_which_are_wordpress_rewrite_nothing(): void
    {
        $site = new FakeWordPressSite(['DB_NAME' => 'live_wp', 'table_prefix' => 'wp_'], ['home' => 'https://example.com']);

        HostRewrite::ofPushedTables($site->wordPress(), [['database' => 'live_shop', 'name' => 'orders']])
            ->run(['staging.example.com'], 'example.com');

        $this->assertSame([], $site->searchReplaces());
    }

    public function test_without_pushed_tables_every_prefixed_table_is_rewritten(): void
    {
        $site = new FakeWordPressSite([], ['home' => 'https://example.com']);

        HostRewrite::ofPushedTables($site->wordPress(), null)->run(['staging.example.com'], 'example.com');

        $this->assertSame('--regex', $site->searchReplaces()[0][3]);
        $this->assertSame([], array_filter($site->calls, static fn (array $args): bool => $args[0] === 'config'));
    }

    private function regex(string $host): string
    {
        return HostRewrite::DELIMITER . HostRewrite::pattern($host) . HostRewrite::DELIMITER;
    }
}
