<?php

namespace Tests\Unit\Apis;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * engine#524: entrypoint.sh and entrypoint.d/ are only ever read inside the
 * account (entrypoint.sh runs each entrypoint.d/*.sh once at boot; the egress
 * guard service only reads entrypoint.d/egress-guard.sh). Mounting them
 * read-write let a tenant with root inside the account's own inner Docker
 * socket plant a symlink there for core's next write to follow.
 */
class DindAccountEntrypointReadOnlyTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    /**
     * @return list<string>
     */
    private function volumes(): array
    {
        $this->assertFileExists(self::TEMPLATE, 'the dind account template moved');

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

        return (array) ($parsed['services']['dind']['volumes'] ?? []);
    }

    public function test_entrypoint_sh_is_read_only(): void
    {
        $this->assertContains('./entrypoint.sh:/entrypoint.sh:ro', $this->volumes());
    }

    public function test_entrypoint_d_is_read_only(): void
    {
        $this->assertContains('./entrypoint.d/:/entrypoint.d/:ro', $this->volumes());
    }

    /** services/ was already :ro; the point is every account-template mount now is. */
    public function test_every_account_template_mount_is_read_only(): void
    {
        foreach ($this->volumes() as $volume) {
            $volume = (string) $volume;
            if (!str_starts_with($volume, './')) {
                continue; // not a template file -- the account's own /home.
            }
            $this->assertStringEndsWith(':ro', $volume, "{$volume} is writable from inside the account");
        }
    }

    /** entrypoint.sh only reads entrypoint.d/*.sh; nothing in the account writes either path. */
    public function test_the_account_image_only_reads_its_entrypoint_files(): void
    {
        $dir = dirname(self::TEMPLATE);
        $entrypoint = (string) file_get_contents($dir . '/entrypoint.sh');

        $this->assertMatchesRegularExpression('/for f in \/entrypoint\.d\/\*\.sh/', $entrypoint);
        $this->assertStringNotContainsString('> /entrypoint', $entrypoint);
        $this->assertStringNotContainsString('>> /entrypoint', $entrypoint);

        $guardRun = (string) file_get_contents($dir . '/services/egress-guard/run');
        $this->assertStringContainsString('[ -f /entrypoint.d/egress-guard.sh ]', $guardRun);
        $this->assertStringNotContainsString('> /entrypoint', $guardRun);
    }
}
