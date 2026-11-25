<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use PHPUnit\Framework\TestCase;

/**
 * A Next.js export is a site of pages, not a single-page app: `/about` is `about.html`, a wrong URL is its own 404.html,
 * and a `basePath` export links to `/<base>/_next/...` while writing the files without the prefix.
 */
class NextExportNginxConfigTest extends TestCase
{
    public function test_each_route_is_its_own_page_and_a_wrong_one_is_a_404(): void
    {
        $conf = NginxConfig::nextExport();

        $this->assertStringContainsString('try_files $uri $uri.html $uri/ =404;', $conf);
        $this->assertStringContainsString('error_page 404 /404.html;', $conf);
        $this->assertStringNotContainsString('/index.html;', $conf);
        $this->assertStringNotContainsString('rewrite', $conf);
    }

    public function test_dotfiles_stay_refused_and_acme_reachable(): void
    {
        $conf = NginxConfig::nextExport('/docs');

        $this->assertMatchesRegularExpression('/location\s+~\s+\/\\\\\.\s*\{\s*deny all;/', $conf);
        $this->assertStringContainsString('location ^~ /.well-known/', $conf);
        $this->assertStringNotContainsString('location ^~ /docs/', $conf, 'a ^~ prefix would skip the dotfile deny');
    }

    public function test_a_base_path_is_stripped_and_the_root_sent_to_it(): void
    {
        $conf = NginxConfig::nextExport('/nextjs-github-pages');

        $this->assertStringContainsString('return 302 /nextjs-github-pages/;', $conf);
        $this->assertStringContainsString('location = /nextjs-github-pages {', $conf);
        $this->assertStringContainsString('rewrite ^/nextjs-github-pages(/.*)$ $1 break;', $conf);
    }

    public function test_an_unsafe_base_path_is_dropped(): void
    {
        foreach (['/a b', '/a;b', '/..', '/a/../b', 'docs', '/a{b}'] as $path) {
            $this->assertStringNotContainsString('rewrite', NginxConfig::nextExport($path), $path);
        }
    }
}
