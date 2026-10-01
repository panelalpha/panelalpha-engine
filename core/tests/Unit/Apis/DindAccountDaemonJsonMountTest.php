<?php

namespace Tests\Unit\Apis;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * engine#312: an account's /etc/docker/daemon.json is rendered on the host
 * ({@see \App\System\Project\Dind\AccountTemplate::daemonJson()}) and mounted
 * in, instead of being patched with a shell inside the account.
 */
class DindAccountDaemonJsonMountTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    /**
     * @return list<string>
     */
    private function volumes(): array
    {
        $this->assertFileExists(self::TEMPLATE, 'the dind account template moved');

        // Blade, so drop directives and lines that are only an interpolation
        // (the optional cpu/memory limits); what is left parses as plain YAML.
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

    public function test_daemon_json_is_mounted_from_the_account_template_dir(): void
    {
        $this->assertContains('./daemon.json:/etc/docker/daemon.json:ro', $this->volumes());
    }

    public function test_the_mount_is_read_only(): void
    {
        foreach ($this->volumes() as $volume) {
            if (str_starts_with((string) $volume, './daemon.json:')) {
                $this->assertStringEndsWith(
                    ':ro',
                    (string) $volume,
                    'writable would let the account rewrite its own trusted registries'
                );

                return;
            }
        }

        $this->fail('daemon.json mount not found');
    }
}
