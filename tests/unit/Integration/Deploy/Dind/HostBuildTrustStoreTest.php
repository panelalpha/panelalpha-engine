<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Images;
use PHPUnit\Framework\TestCase;
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
    private const HOST_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    private const CONTAINER_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    private function account(): EngineAccount
    {
        return new EngineAccount('koel', '/home/koel', '1001:1002');
    }

    /** @return array<string, mixed> */
    private function build(?string $caBundle): array
    {
        return LocalRoot::build((new DindHostBuilder(null, $caBundle))->nodeBuildArgv(
            $this->account(),
            Images::NODE_IMAGE,
            'pnpm install --frozen-lockfile',
            'pnpm build'
        ));
    }

    public function test_the_bundle_is_mounted_read_only(): void
    {
        $this->assertContains(
            self::HOST_BUNDLE.':'.self::CONTAINER_BUNDLE.':ro',
            $this->build(self::HOST_BUNDLE)['mounts'],
            'the build needs the host bundle, and has no business writing to it'
        );
    }

    /**
     * The mount on its own does nothing: OpenSSL's default CAfile in that
     * image is `/usr/lib/ssl/cert.pem`, which is absent, and its default
     * CApath wants `c_rehash` symlinks the bundle does not carry. The
     * variable is also what openssl-probe reads, which is how
     * rustls-native-certs -- and so Vite+ -- finds its roots.
     */
    public function test_ssl_cert_file_names_the_mounted_path(): void
    {
        $this->assertSame(self::CONTAINER_BUNDLE, $this->build(self::HOST_BUNDLE)['environment']['SSL_CERT_FILE'] ?? null);
    }

    /**
     * Declared in one place so they cannot drift apart. A variable pointing
     * at a path nothing mounted is the same failure with a longer diagnosis.
     */
    public function test_the_variable_points_at_a_path_that_is_actually_mounted(): void
    {
        $build = $this->build(self::HOST_BUNDLE);

        $declared = $build['environment']['SSL_CERT_FILE'] ?? null;
        $mounted = [];
        foreach ($build['mounts'] as $mount) {
            $parts = explode(':', $mount);
            if (isset($parts[1])) {
                $mounted[] = $parts[1];
            }
        }

        $this->assertNotNull($declared, 'SSL_CERT_FILE must be declared');
        $this->assertContains($declared, $mounted);
    }

    /**
     * A host with no bundle contributes neither half, rather than mounting
     * something that is not there -- `docker run -v` on a missing path
     * creates a *directory*, and a directory where OpenSSL wants a file is a
     * worse failure than the one being fixed.
     */
    public function test_a_host_without_a_bundle_changes_nothing(): void
    {
        $build = $this->build('');

        $this->assertArrayNotHasKey('SSL_CERT_FILE', $build['environment']);
        $this->assertNotContains(self::CONTAINER_BUNDLE, $build['mounts']);
        // And the build itself is still built.
        $this->assertStringContainsString('pnpm build', $build['script']);
    }

    /** A recipe that sets its own still wins: caller env is applied last. */
    public function test_a_recipe_may_override_it(): void
    {
        $build = LocalRoot::build((new DindHostBuilder(null, self::HOST_BUNDLE))->nodeBuildArgv(
            $this->account(),
            Images::NODE_IMAGE,
            'pnpm install',
            'pnpm build',
            ['SSL_CERT_FILE' => '/app/certs/custom.pem']
        ));

        $this->assertSame('/app/certs/custom.pem', $build['environment']['SSL_CERT_FILE'] ?? null);
        $this->assertContains(self::HOST_BUNDLE.':'.self::CONTAINER_BUNDLE.':ro', $build['mounts']);
    }

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
