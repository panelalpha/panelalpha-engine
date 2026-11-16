<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * A fingerprint's whole job is collapsing the same failure seen on many
 * machines into one row. These tests pin both halves of that: the noise that
 * must not split a group, and the signal that must not merge two.
 */
class FingerprintTest extends TestCase
{
    /** The same npm failure on two hosts, with different digests and timings. */
    public function test_same_failure_on_two_hosts_gets_one_fingerprint(): void
    {
        $a = <<<'OUT'
#14 12.7 npm ERR! code ERESOLVE
#14 12.7 npm ERR! While resolving: app@1.0.0
#14 ERROR: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1
OUT;
        $b = <<<'OUT'
#9 84.2 npm ERR! code ERESOLVE
#9 84.2 npm ERR! While resolving: app@1.0.0
#9 ERROR: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1
OUT;

        $this->assertSame(
            Fingerprint::of('dependency-conflict', 'nextjs', 'node', $a),
            Fingerprint::of('dependency-conflict', 'nextjs', 'node', $b)
        );
    }

    public function test_paths_and_digests_do_not_split_a_group(): void
    {
        $a = 'failed to solve: sha256:9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a copy /home/alpha1/project/app';
        $b = 'failed to solve: sha256:0011223344556677889900aabbccddeeff001122 copy /home/beta22/project/app';

        $this->assertSame(
            Fingerprint::of('build-step-failed', 'dockerfile', null, $a),
            Fingerprint::of('build-step-failed', 'dockerfile', null, $b)
        );
    }

    public function test_a_different_rule_is_a_different_group(): void
    {
        $this->assertNotSame(
            Fingerprint::of('disk-full', 'nextjs', 'node', 'same text'),
            Fingerprint::of('out-of-memory', 'nextjs', 'node', 'same text')
        );
    }

    public function test_a_different_strategy_is_a_different_group(): void
    {
        $this->assertNotSame(
            Fingerprint::of('build-step-failed', 'nextjs', 'node', 'same text'),
            Fingerprint::of('build-step-failed', 'laravel', 'php', 'same text')
        );
    }

    /**
     * Unexplained failures are the ones worth reading first, so they must still
     * group by their own text rather than all collapsing into one bucket.
     */
    public function test_unexplained_failures_still_group_by_text(): void
    {
        $one = Fingerprint::of(null, 'railpack', null, 'mise: failed to compile ruby');
        $two = Fingerprint::of(null, 'railpack', null, 'mise: failed to compile ruby');
        $other = Fingerprint::of(null, 'railpack', null, 'bundler: could not locate Gemfile');

        $this->assertSame($one, $two);
        $this->assertNotSame($one, $other);
    }

    /**
     * The certificate warning the engine reports names the site's own domain, so
     * one broken TLS setup produced a problem per hostname instead of one.
     */
    public function test_the_same_certificate_warning_on_two_domains_is_one_group(): void
    {
        $a = 'https://shop.'.TestNetwork::private(44).'.nip.io is served a certificate browsers will refuse'
            .' (self_signed, issued by Unknown). Request a real one with `ssl:project-cert:request`'
            .' once the name resolves to this host.';
        $b = 'https://staging.e2e-static-1.sslip.io is served a certificate browsers will refuse'
            .' (self_signed, issued by Unknown). Request a real one with `ssl:project-cert:request`'
            .' once the name resolves to this host.';

        $this->assertSame(
            Fingerprint::of('ssl-untrusted', 'static', null, $a),
            Fingerprint::of('ssl-untrusted', 'static', null, $b)
        );
    }

    /**
     * The private-address warning names the project's domain in the middle of a
     * sentence rather than as a URL, and split exactly the same way.
     */
    public function test_the_same_private_address_warning_on_two_domains_is_one_group(): void
    {
        $a = 'The application is deployed but not reachable from the internet: benchmark.invalid'
            ." resolves to this host's private address, so it answers on this network only.";
        $b = 'The application is deployed but not reachable from the internet: acme42.com'
            ." resolves to this host's private address, so it answers on this network only.";

        $this->assertSame(
            Fingerprint::of('serving-unreachable', 'dockerfile', null, $a),
            Fingerprint::of('serving-unreachable', 'dockerfile', null, $b)
        );
    }

    /**
     * Which registry an image comes from is the cause of the failure, not the
     * install it happened on: those two must never collapse into one group.
     */
    public function test_a_registry_host_in_an_image_reference_stays_distinct(): void
    {
        $ghcr = 'failed to resolve source metadata for ghcr.io/acme/base:1.0: not found';
        $docker = 'failed to resolve source metadata for docker.io/acme/base:1.0: not found';

        $this->assertNotSame(
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $ghcr),
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $docker)
        );
    }

    public function test_the_hosts_address_does_not_split_a_group(): void
    {
        $a = 'connect to '.TestNetwork::private(44).':5432 failed: connection refused';
        $b = 'connect to '.TestNetwork::private2(2).':5432 failed: connection refused';

        $this->assertSame(
            Fingerprint::of('database-unreachable', 'laravel', 'php', $a),
            Fingerprint::of('database-unreachable', 'laravel', 'php', $b)
        );
    }

    public function test_normalize_replaces_urls_hosts_and_addresses(): void
    {
        $address = TestNetwork::private(44);
        $normalized = Fingerprint::normalize(
            "GET https://benchmark.invalid/login failed from {$address}, tried [2001:db8::1]"
        );

        $this->assertStringContainsString('<url>', $normalized);
        $this->assertStringContainsString('<ip>', $normalized);
        $this->assertStringNotContainsString('benchmark.invalid', $normalized);
        $this->assertStringNotContainsString($address, $normalized);
        $this->assertStringNotContainsString('2001:db8::1', $normalized);
    }

    /**
     * A file extension is a top-level-domain lookalike, and a build log is full
     * of them: `package.json` is a file, not a host.
     */
    public function test_a_file_name_is_not_a_hostname(): void
    {
        $this->assertSame('cannot read package.json', Fingerprint::normalize('cannot read package.json'));
    }

    /**
     * A build log is full of dotted code identifiers and file names that look like
     * hostnames; folding them would merge failures that have nothing in common.
     */
    #[DataProvider('dottedNonHostProvider')]
    public function test_a_dotted_name_that_is_not_a_host_is_kept(string $text): void
    {
        $this->assertStringNotContainsString('<host>', Fingerprint::normalize($text));
    }

    /** @return array<string, array{string}> */
    public static function dottedNonHostProvider(): array
    {
        return [
            'javascript' => ['TypeError: module.exports is not a function'],
            'logger call' => ['console.info is not defined'],
            'java class' => ['Exception in thread "main" java.lang.NullPointerException'],
            'dotnet sdk' => ['<Project Sdk="Microsoft.NET.Sdk">'],
            'python module' => ["ModuleNotFoundError: No module named 'django.core.management'"],
            'go module file' => ['missing go.sum entry for module'],
            'toml table' => ['[tool.poetry] section not found'],
            'env example' => ["copy('.env.example', '.env')"],
        ];
    }

    /** A private registry with a port is as much the cause as one without. */
    public function test_a_registry_host_with_a_port_stays_distinct(): void
    {
        $one = 'pull access denied for registry.acme.com:5000/team/app:1.0';
        $two = 'pull access denied for registry.other.org:5000/team/app:1.0';

        $this->assertNotSame(
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $one),
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $two)
        );
    }

    /**
     * A registry named only through its API is still the cause of the failure:
     * two registries must not collapse just because both arrived as URLs.
     */
    public function test_a_registry_api_url_keeps_its_host(): void
    {
        $ghcr = 'Get "https://ghcr.io/v2/acme/base/manifests/1.0": denied';
        $docker = 'Get "https://registry-1.docker.io/v2/library/node/manifests/20": denied';

        $this->assertNotSame(
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $ghcr),
            Fingerprint::of('base-image-unavailable', 'dockerfile', null, $docker)
        );
    }

    public function test_normalize_collapses_a_hostname_wherever_it_appears(): void
    {
        $this->assertStringContainsString('<host>', Fingerprint::normalize(
            'x509: certificate is valid for *.nip.io, not staging.e2e-static-1.sslip.io'
        ));
    }

    public function test_normalize_removes_buildkit_prefixes_numbers_and_paths(): void
    {
        $normalized = Fingerprint::normalize('#12 4.5 cp /home/bob/project/x.js -> 42 files in 1.2s');

        $this->assertStringNotContainsString('#12', $normalized);
        $this->assertStringNotContainsString('/home/bob', $normalized);
        $this->assertStringNotContainsString('42', $normalized);
    }

    public function test_fingerprint_is_a_fixed_width_hex_string(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{' . Fingerprint::LENGTH . '}$/',
            Fingerprint::of('disk-full', 'nextjs', 'node', 'x')
        );
    }

    /**
     * The account key correlates repeated failures without naming anyone, so
     * the same username on two installs must not look like one account.
     */
    public function test_account_key_is_salted_per_install(): void
    {
        $this->assertNotSame(
            Fingerprint::account('install-a', 'wordpress1'),
            Fingerprint::account('install-b', 'wordpress1')
        );
        $this->assertSame(
            Fingerprint::account('install-a', 'wordpress1'),
            Fingerprint::account('install-a', 'wordpress1')
        );
    }

    public function test_account_key_does_not_contain_the_username(): void
    {
        $this->assertStringNotContainsString(
            'wordpress1',
            Fingerprint::account('install-a', 'wordpress1')
        );
    }
}
