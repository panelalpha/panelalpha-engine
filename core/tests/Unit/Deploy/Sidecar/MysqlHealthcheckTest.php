<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\SidecarDialects;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The database healthcheck the engine writes was dead two ways at once.
 *
 * Kanboard is where it showed: its `db` container sat `unhealthy` with a
 * FailingStreak of 83, every probe logging
 * `exit=127 out=/bin/sh: 1: mysqladmin: not found`.
 *
 *  - `$VAR` and not `$$VAR`. Compose interpolates `$VAR` itself, at parse
 *    time, against a `.env` the engine writes empty -- so the password was
 *    substituted away before the container saw it, leaving
 *    `mysqladmin ping -p""` and a `variable is not set` warning on every
 *    compose command in the project.
 *  - `mysqladmin` is gone from MariaDB 11+ images. On mariadb:lts (12.3.3)
 *    it exits 127; `mariadb-admin` exits 0. On mysql:8 it is the other way
 *    round. Trying both covers either.
 *
 * With the emitted YAML there is no compose warning, the container reaches
 * `Healthy`, and a dependent service gated on `condition: service_healthy`
 * actually starts.
 */
class MysqlHealthcheckTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function healthcheck(): array
    {
        return SidecarDialects::serviceOverridesFor('mysql')['healthcheck'] ?? [];
    }

    private function command(): string
    {
        return (string) ($this->healthcheck()['test'][1] ?? '');
    }

    /**
     * The escape compose needs. A single `$` is expanded by compose, from a
     * file the engine writes empty; `$$` reaches the container's own shell,
     * where the variable is actually set.
     */
    public function test_the_password_survives_compose_interpolation(): void
    {
        $command = $this->command();

        $this->assertStringContainsString('$$MYSQL_ROOT_PASSWORD', $command);
        $this->assertStringNotContainsString(
            '-p"$MYSQL_ROOT_PASSWORD"',
            $command,
            'a single $ is substituted away before the container sees it'
        );
    }

    /** Neither binary exists in both image families, so try both. */
    public function test_it_works_on_mariadb_and_on_mysql(): void
    {
        $command = $this->command();

        $this->assertStringContainsString('mariadb-admin ping', $command);
        $this->assertStringContainsString('mysqladmin ping', $command);
        $this->assertStringContainsString('||', $command, 'the first must be allowed to fail');
        $this->assertLessThan(
            strpos($command, 'mysqladmin ping'),
            strpos($command, 'mariadb-admin ping'),
            'mariadb-admin is tried first; mysqladmin is the fallback'
        );
    }

    /**
     * Escaping is a property of the rendered file, not of the PHP string, so
     * assert it after a YAML round trip -- that is what compose reads.
     */
    public function test_the_escape_survives_being_written_out(): void
    {
        $yaml = Yaml::dump(['services' => ['db' => ['healthcheck' => $this->healthcheck()]]], 6, 2);

        $this->assertStringContainsString('$$MYSQL_ROOT_PASSWORD', $yaml);
        $this->assertSame(
            $this->command(),
            Yaml::parse($yaml)['services']['db']['healthcheck']['test'][1]
        );
    }

    /** CMD-SHELL, because the fallback needs a shell to run `||`. */
    public function test_it_is_a_shell_test(): void
    {
        $this->assertSame('CMD-SHELL', $this->healthcheck()['test'][0] ?? null);
    }
}
