<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\PublicUrlEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Telling an application what its external address is.
 *
 * Behind the hosting reverse-proxy an app sees plain HTTP on an internal
 * hostname, so unless told otherwise it writes http:// links and its own
 * container name into password-reset emails. Every framework spells the
 * variable differently, so the engine sets all the usual names rather than
 * guessing which one this application reads.
 */
class PublicUrlEnvironmentTest extends TestCase
{
    public function test_every_common_alias_is_set(): void
    {
        $env = PublicUrlEnvironment::for('https://shop.example.com');

        foreach (['URL', 'PUBLIC_URL', 'BASE_URL', 'APP_URL', 'ASSET_URL', 'SITE_URL', 'ORIGIN'] as $key) {
            $this->assertSame('https://shop.example.com', $env[$key], $key);
        }
    }

    public function test_https_also_sets_the_flags_that_force_secure_links(): void
    {
        // The app sees plain HTTP from the proxy, so without these it
        // downgrades its own generated URLs and browsers block the assets.
        $env = PublicUrlEnvironment::for('https://shop.example.com');

        $this->assertSame('on', $env['HTTPS']);
        $this->assertSame('true', $env['SSL']);
        $this->assertSame('true', $env['FORCE_SSL']);
    }

    public function test_plain_http_does_not_claim_to_be_secure(): void
    {
        $env = PublicUrlEnvironment::for('http://shop.example.com');

        $this->assertSame('http://shop.example.com', $env['APP_URL']);
        $this->assertArrayNotHasKey('HTTPS', $env);
    }

    public function test_the_scheme_is_matched_case_insensitively(): void
    {
        $this->assertSame('on', PublicUrlEnvironment::for('HTTPS://shop.example.com')['HTTPS']);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame(
            'https://shop.example.com',
            PublicUrlEnvironment::for('  https://shop.example.com  ')['APP_URL']
        );
    }

    public function test_nothing_is_set_without_a_usable_url(): void
    {
        // Setting APP_URL to a bare hostname is worse than leaving it: the app
        // would generate links to a scheme-less address nothing can follow.
        foreach ([null, '', 'shop.example.com', 'ftp://shop.example.com', 'javascript:alert(1)'] as $url) {
            $this->assertSame([], PublicUrlEnvironment::for($url), var_export($url, true));
        }
    }

    public function testTheHostIsSetForAnApacheStyleNameBasedVhost(): void
    {
        $env = PublicUrlEnvironment::for('https://signaturepdf-f18d.panelalpha.online');

        $this->assertSame('signaturepdf-f18d.panelalpha.online', $env['SERVERNAME']);
        $this->assertSame('signaturepdf-f18d.panelalpha.online', $env['SERVER_NAME']);
    }

    /**
     * Shlink reads DEFAULT_DOMAIN as a bare `host[:port]` and takes the scheme
     * from IS_HTTPS_ENABLED, so it is a host alias and not a URL one. Left to
     * the repository's own dev stack it stays `localhost:8000` and every short
     * URL the deploy creates points at the container.
     */
    public function testTheBareHostIsAlsoSetAsTheDefaultDomain(): void
    {
        $env = PublicUrlEnvironment::for('https://shlink-d670.panelalpha.online');

        $this->assertSame('shlink-d670.panelalpha.online', $env['DEFAULT_DOMAIN']);
    }

    /** The scheme, port and path belong to the URL keys, not to a ServerName. */
    public function testTheHostIsTheBareHostname(): void
    {
        $env = PublicUrlEnvironment::for('http://example.test:8080/sub/path');

        $this->assertSame('example.test', $env['SERVERNAME']);
        $this->assertSame('http://example.test:8080/sub/path', $env['URL']);
    }

    /** Routing a proxy sidecar is a different claim from the app's own name. */
    public function testVirtualHostIsNotSet(): void
    {
        $this->assertArrayNotHasKey('VIRTUAL_HOST', PublicUrlEnvironment::for('https://example.test'));
    }

    public function testABlankBaseUrlIsNotOneToFill(): void
    {
        $this->assertNotContains('BASE_URL', PublicUrlEnvironment::blankFillKeys());
        $this->assertContains('ORIGIN', PublicUrlEnvironment::blankFillKeys());
    }

    public function testBaseUrlSetBlankOrToAPathIsAPathPrefix(): void
    {
        $this->assertSame(['BASE_URL'], PublicUrlEnvironment::pathPrefixKeysIn(["BASE_URL=\n"]));
        $this->assertSame(['BASE_URL'], PublicUrlEnvironment::pathPrefixKeysIn([null, "BASE_URL=\"/app\"\n"]));
        $this->assertSame([], PublicUrlEnvironment::pathPrefixKeysIn(["BASE_URL=http://localhost:3000\n"]));
        $this->assertSame([], PublicUrlEnvironment::pathPrefixKeysIn(["APP_URL=\n# BASE_URL=\n", null]));
    }

    public function testNothingIsSetWithoutAUrl(): void
    {
        $this->assertSame([], PublicUrlEnvironment::for(null));
        $this->assertSame([], PublicUrlEnvironment::for('example.test'));
    }
}
