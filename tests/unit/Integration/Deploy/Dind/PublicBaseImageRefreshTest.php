<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\PublicBaseImageRefreshFixtures;

/**
 * `node:22-bookworm-slim` moves with every Node patch release. An account or a
 * host that already held it never pulled it again, so a redeploy after the
 * Node.js security release of 21.10.2026 still built on the vulnerable Node.
 */
class PublicBaseImageRefreshTest extends TestCase
{
    use PublicBaseImageRefreshFixtures;

    public function test_the_host_pulls_a_public_build_image_again_and_never_fails(): void
    {
        $argv = (new DindImageStore())->hostRefreshImageArgv(self::NODE);

        $this->assertSame([LocalRoot::CLIENT, '--host', 'host.refreshPublicImage', '--', self::NODE], $argv);
        $this->assertSame([], LocalRoot::plain($argv));
    }
}
