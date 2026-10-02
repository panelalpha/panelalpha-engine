<?php

namespace Tests\Unit\Apis;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `/run` inside an account container has to be ephemeral.
 *
 * Everywhere else on Linux it is a tmpfs that starts empty at boot, and
 * pidfiles are built on exactly that. In this image it was part of the
 * container's persistent app config, so `/run/docker.pid` outlived the process
 * that wrote it: a host reboot SIGKILLs the account before dockerd can
 * remove it, and on the next start the init script read that pid, found the
 * number reused by an unrelated process, and refused to start the daemon.
 *
 * The account then came up with no Docker and every application inside it
 * stayed down — silently, because nothing checks the inner daemon. It is a
 * race on pid reuse, so it hit one account out of six on the reboot that
 * exposed it, which is exactly what makes it worth a test rather than
 * institutional memory.
 */
class DindAccountRunIsEphemeralTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    private function service(): array
    {
        $this->assertFileExists(self::TEMPLATE, 'the dind account template moved');

        // The template is Blade. Drop the directives, and drop the lines
        // that are nothing but an interpolation (the optional cpu/memory
        // limits) — what is left is the static structure, which is what this
        // test is about.
        $lines = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents(self::TEMPLATE)) ?: [] as $line) {
            if (preg_match('/^\s*@/', $line) === 1) {
                continue;
            }
            if (preg_match('/^\s*\{\{.*\}\}\s*$/', $line) === 1) {
                continue;
            }
            $lines[] = preg_replace('/\{\{.*?\}\}/', 'x', $line) ?? $line;
        }

        $parsed = Yaml::parse(implode("\n", $lines));

        return $parsed['services']['dind'] ?? [];
    }

    public function test_run_is_a_tmpfs(): void
    {
        $tmpfs = $this->service()['tmpfs'] ?? [];

        $this->assertNotEmpty($tmpfs, '/run must be a tmpfs or pidfiles survive a reboot');
        $this->assertStringStartsWith('/run', (string) $tmpfs[0]);
    }

    public function test_the_tmpfs_is_bounded(): void
    {
        // tmpfs pages count against the account's own memory limit, and the
        // template sets one. Real usage is ~276K, so the cap is headroom, not
        // a constraint — but an unbounded /run would default to half the
        // host's RAM and could be filled from inside the account.
        $entry = (string) ($this->service()['tmpfs'][0] ?? '');

        $this->assertMatchesRegularExpression('/\bsize=\d+[kmg]\b/i', $entry);
    }

    public function test_nothing_from_the_host_is_mounted_at_run(): void
    {
        // The whole point of the account container is that the tenant cannot
        // reach the host's daemon. A bind mount here — /run:/run — would hand
        // over /run/docker.sock, which is root on the host. tmpfs shares
        // nothing; a bind would share everything.
        foreach ((array) ($this->service()['volumes'] ?? []) as $volume) {
            $volume = is_array($volume) ? ($volume['source'] ?? '') . ':' . ($volume['target'] ?? '') : (string) $volume;
            $this->assertStringNotContainsString(':/run', $volume);
            $this->assertStringNotContainsString('docker.sock', $volume);
        }
    }

    public function test_the_account_still_only_mounts_its_own_files(): void
    {
        // A guard on the isolation boundary generally, not just /run.
        foreach ((array) ($this->service()['volumes'] ?? []) as $volume) {
            // lxcfs's virtualised /proc files, read-only, are the one exception.
            if (is_array($volume)) {
                $this->assertStringStartsWith('/var/lib/lxcfs/proc/', (string) ($volume['source'] ?? ''));
                $this->assertTrue($volume['read_only'] ?? false, 'an lxcfs file must be mounted read-only');
                continue;
            }
            $source = explode(':', (string) $volume)[0];

            $this->assertTrue(
                str_starts_with($source, './') || str_starts_with($source, '/home/'),
                "account mounts something outside its own files: {$source}"
            );
        }
    }
}
