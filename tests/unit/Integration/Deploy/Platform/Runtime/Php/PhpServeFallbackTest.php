<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Php;

use PHPUnit\Framework\TestCase;
use Tests\Support\PhpServeFallbackFixtures;

/**
 * What the container publishes when nothing declared a document root.
 *
 * `PhpDocroot::detect()` returns '' for a repository with no index file in any
 * candidate directory, `environment()` then emits no PA_DOCROOT, and the serve
 * script decided. It used to answer `/app` -- the whole checkout, with Apache's
 * PHP handler active over it. On inovector/mixpost, a Laravel *package* with no
 * index.php anywhere: `composer.lock` came back 200 at 418,679 bytes, and every
 * .php under src/, config/, routes/ and vendor/ was directly invocable.
 *
 * The distinction the script now makes is the one that was missing: an app
 * with a front controller in an unexpected place is still worth guessing at,
 * and a checkout with no front controller at all has nothing to serve, so
 * serving the repository cannot be helping.
 */
class PhpServeFallbackTest extends TestCase
{
    use PhpServeFallbackFixtures;

    public function test_it_is_still_valid_shell(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pa-serve');
        $this->assertIsString($file);
        file_put_contents($file, $this->script());
        exec('sh -n ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }
}
