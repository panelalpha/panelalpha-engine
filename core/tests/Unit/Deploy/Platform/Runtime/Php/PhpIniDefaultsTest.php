<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\Php\PhpIniDefaults;
use PHPUnit\Framework\TestCase;

class PhpIniDefaultsTest extends TestCase
{
    /** @return array<string, string> */
    private function directives(): array
    {
        return parse_ini_string(PhpIniDefaults::ini(), false, INI_SCANNER_RAW) ?: [];
    }

    public function test_the_file_php_refuses_to_guess_is_valid_ini(): void
    {
        $this->assertNotSame([], $this->directives());
    }

    /**
     * The whole point: an error printed into the body sends the headers with
     * it, so a single notice in a fresh checkout costs the app its session and
     * with it the page -- and leaks account paths to anonymous visitors on the
     * way.
     */
    public function test_errors_do_not_reach_the_response_body(): void
    {
        $directives = $this->directives();

        $this->assertSame('Off', $directives['display_errors'] ?? null);
        $this->assertSame('Off', $directives['display_startup_errors'] ?? null);
    }

    /** Off the page, not gone: the operator still reads them in the log. */
    public function test_errors_are_still_logged(): void
    {
        $this->assertSame('On', $this->directives()['log_errors'] ?? null);
        $this->assertArrayNotHasKey(
            'error_log',
            $this->directives(),
            'unset is what sends them to the Apache log and to stderr on the CLI'
        );
    }

    public function test_responses_do_not_advertise_the_php_version(): void
    {
        $this->assertSame('Off', $this->directives()['expose_php'] ?? null);
    }

    /**
     * php.ini-production spells this "GPCS", dropping the `E` that merges the
     * container environment into $_SERVER and $_ENV -- which is how the engine
     * hands a project most of what it knows. Leaving it unset keeps the
     * compiled-in EGPCS, so this change is the disclosure and nothing else.
     */
    public function test_the_container_environment_still_reaches_superglobals(): void
    {
        $this->assertArrayNotHasKey('variables_order', $this->directives());
    }

    /** conf.d, so .user.ini, .htaccess and ini_set() all still win. */
    public function test_it_is_installed_where_an_app_can_override_it(): void
    {
        $this->assertStringEndsWith('.ini', PhpIniDefaults::INI_PATH);
        $this->assertStringContainsString('/conf.d/', PhpIniDefaults::INI_PATH);
    }

    public function test_the_base_image_writes_it(): void
    {
        $dockerfile = (string) PhpBaseImage::dockerfile('php:8.3-apache-bookworm');

        $this->assertStringContainsString('> ' . PhpIniDefaults::INI_PATH, $dockerfile);
        $this->assertStringContainsString('display_errors = Off', $dockerfile);
        $this->assertStringContainsString('expose_php = Off', $dockerfile);
    }
}
