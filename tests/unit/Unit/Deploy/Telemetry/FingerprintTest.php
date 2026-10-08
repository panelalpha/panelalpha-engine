<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\Fingerprint;
use PHPUnit\Framework\TestCase;

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
