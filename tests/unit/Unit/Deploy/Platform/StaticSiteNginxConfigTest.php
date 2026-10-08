<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The nginx config a site of documents is served by.
 *
 * Two things are worth asserting. The first is that it is not the single-page
 * config: a site of pages that answers every wrong URL with its front page
 * turns a 404 into a page saying the wrong thing, quietly.
 *
 * The second is the entry path. It comes out of a customer's repository and
 * goes into a config file, so a filename is a filename and anything that
 * could be read as nginx syntax is dropped rather than escaped -- serving
 * index.html, which is where this started and never worse.
 */
class StaticSiteNginxConfigTest extends TestCase
{
    public function test_it_points_the_root_request_at_the_entry_document(): void
    {
        $conf = NginxConfig::site('home.html');

        $this->assertStringContainsString('location = /', $conf);
        $this->assertStringContainsString('try_files "/home.html" =404;', $conf);
    }

    public function test_a_missing_entry_leaves_nginx_looking_for_index_html(): void
    {
        $conf = NginxConfig::site(null);

        $this->assertStringNotContainsString('location = /', $conf);
        $this->assertStringContainsString('index index.html index.htm;', $conf);
    }

    /** A site of pages, not a single-page app: a wrong URL is a 404. */
    public function test_it_does_not_rewrite_every_path_to_the_front_page(): void
    {
        $conf = NginxConfig::site('home.html');

        $this->assertStringContainsString('try_files $uri $uri/ =404;', $conf);
        $this->assertStringNotContainsString('$uri/ /index.html', $conf);
    }

    /**
     * The site is served straight out of ~/project, so the engine's own files
     * are sitting in the document root beside it.
     */
    public function test_the_engines_own_files_are_not_served(): void
    {
        $conf = NginxConfig::site('home.html');

        $this->assertStringContainsString('docker-compose', $conf);
        $this->assertStringContainsString('panelalpha', $conf);
        $this->assertStringContainsString('deny all;', $conf);
    }

    #[DataProvider('entries')]
    public function test_it_accepts_a_filename_and_refuses_anything_else(string $entry, ?string $expected): void
    {
        $conf = NginxConfig::site($entry);
        $matched = preg_match('/try_files "([^"]*)" =404;/', $conf, $m) === 1 ? $m[1] : null;

        $this->assertSame($expected, $matched);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function entries(): array
    {
        return [
            'a page' => ['home.html', '/home.html'],
            'a page one directory down' => ['dist/index.html', '/dist/index.html'],
            'a name with spaces and brackets' => ['plan (1).html', '/plan (1).html'],
            'traversal' => ['../../etc/passwd', null],
            'an absolute path' => ['/etc/passwd', null],
            'a closing quote and a new block' => ['a";}location / {root /;}#.html', null],
            'an nginx variable' => ['x$document_root.html', null],
            'a newline' => ["home.html\ndeny all;", null],
            'nothing at all' => ['   ', null],
        ];
    }
}
