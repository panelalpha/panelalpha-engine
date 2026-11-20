<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindAccountCleanup;
use PHPUnit\Framework\TestCase;

/**
 * The image refs and home roots account cleanup refuses to hand the gateway.
 */
class DindAccountCleanupRefsTest extends TestCase
{
    public function test_a_ref_the_gateway_would_refuse_is_left_out_of_the_removal(): void
    {
        [$store] = DindAccountCleanup::hostCleanupArgvs('acme', '/home/acme', ['Acme/Search:2', 'mysql:8.4']);

        $this->assertSame(['1', 'mysql:8.4'], array_slice($store, 5));
    }

    public function test_an_unsafe_ref_is_never_a_sidecar_to_remove(): void
    {
        $this->assertSame(['mysql:8.4'], DindAccountCleanup::hostSidecarRefsToRemove(['mysql:8.4', 'evil; rm -rf /', '-rf', '']));
    }

    public function test_a_sibling_scan_outside_a_plain_home_root_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to scan an unexpected home root');

        DindAccountCleanup::otherAccountComposeImagesArgv('home/users', 'alice');
    }
}
