<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Platform\Runtime\Images;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostBuildTrustStoreFixtures;
use Tests\Support\LocalRoot;

/**
 * A host build container has no certificate authorities of its own.
 *
 * `node:{version}-bookworm-slim` -- what the catalogue resolves for every Node
 * build -- ships an empty `/etc/ssl/certs`: no bundle, no hashed links, and no
 * `/usr/lib/ssl/cert.pem`. Node does not notice, because it compiles its own
 * root list in. Everything else in a build does.
 *
 * Koel is what found it. Its frontend builds with Vite+, whose Rust core
 * builds an HTTP client eagerly and aborts outright when it finds no roots --
 * `failed to initialize HTTP client: ... No CA certificates were loaded from
 * the system`, SIGABRT, exit 134, 62 seconds in, before any `install` stage
 * could run. Every Go, Rust and Python tool that verifies TLS through the
 * system store rather than a bundled list is subject to the same failure.
 *
 * The fix is two things that are worthless apart, which is the point of
 * asserting them together. Against `node:20-bookworm-slim` with
 * `--use-openssl-ca`, fetching npm's registry:
 *
 *     mount only            UNABLE_TO_GET_ISSUER_CERT_LOCALLY
 *     SSL_CERT_FILE only    UNABLE_TO_GET_ISSUER_CERT_LOCALLY
 *     both                  200
 */
class HostBuildTrustStoreTest extends TestCase
{
    use HostBuildTrustStoreFixtures;

    /** The sandboxing this sits inside is not loosened to make room for it. */
    public function test_the_sandbox_is_intact(): void
    {
        $request = (new DindHostBuilder(null, self::HOST_BUNDLE))->nodeBuildArgv(
            $this->account(),
            Images::NODE_IMAGE,
            'pnpm install --frozen-lockfile',
            'pnpm build'
        );
        $build = LocalRoot::build($request);

        $this->assertSame([], LocalRoot::plain($request));
        $this->assertSame('1001:1002', $build['user']);
        $this->assertSame('2g', $build['memory']);
    }
}
