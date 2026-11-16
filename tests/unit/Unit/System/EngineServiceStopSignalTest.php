<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A PID 1 with no SIGTERM handler of its own never sees the signal, so a stop of its container waits out Docker's
 * 10 s timeout, and an update starts nothing it recreates until that stop is over. docker-init forwards it.
 */
class EngineServiceStopSignalTest extends TestCase
{
    /** The services without docker-init, and the PID 1 each runs, which stops on SIGTERM itself. */
    private const STOPS_ON_SIGTERM = [
        'core' => 's6-svscan',
        'root-broker' => 's6-svscan',
        'sites-dns' => 'socat',
        'core-db' => 'mariadbd',
        'sites-db' => 'mariadbd',
        'ftp' => 'pure-ftpd',
        'sftp' => 'sshd',
        'sites-phpmyadmin' => 'apache2',
        'cache-registry' => 'registry',
        'cache-registry-writer' => 'registry',
        'registry-proxy' => 'registry',
        'ghcr-proxy' => 'registry',
    ];

    public function test_metrics_runs_python_under_docker_init(): void
    {
        $this->assertStringContainsString('CMD ["python", "server_metrics.py"]', self::file('dockerfiles/Dockerfile-metrics'));
        $this->assertTrue(self::services('docker-compose.yml')['metrics']['init'] ?? false);
    }

    public function test_mail_runs_its_shell_entrypoint_under_docker_init(): void
    {
        $this->assertStringContainsString("service exim4 start\ntail -f /dev/null\n", self::file('dockerfiles/Dockerfile-exim'));
        $this->assertTrue(self::services('docker-compose.yml')['mail']['init'] ?? false);
    }

    /** The LiteSpeed images start the server with lswsctrl and then poll it from a shell loop as PID 1. */
    public function test_a_litespeed_webserver_runs_under_docker_init(): void
    {
        foreach (['litespeed', 'openlitespeed'] as $variant) {
            $this->assertTrue(self::services('docker-compose.yml-'.$variant)['sites-http']['init'] ?? false, $variant);
        }
    }

    public function test_every_other_engine_service_runs_a_pid_1_that_stops_on_sigterm(): void
    {
        foreach (self::services('docker-compose.yml') as $name => $service) {
            if (($service['init'] ?? false) === true) {
                continue;
            }
            $this->assertArrayHasKey($name, self::STOPS_ON_SIGTERM, $name.' runs without docker-init');
        }
    }

    /** A start script shipped here leaves PID 1 to the server, not to its shell. */
    public function test_the_start_scripts_shipped_here_exec_their_server(): void
    {
        $this->assertStringEndsWith("\nexec s6-svscan /run/service\n", self::file('dockerfiles/entrypoint-core.sh'));
        $this->assertStringEndsWith("\nexec s6-svscan /run/service\n", self::file('dockerfiles/entrypoint-root-broker.sh'));
        $this->assertStringContainsString('exec /usr/sbin/sshd -D', self::file('templates/config/sftp/entrypoint.sh'));
        $this->assertStringEndsWith("\nexec \"\$@\"\n", self::file('templates/config/pure-ftpd/entrypoint.sh'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function services(string $compose): array
    {
        return Yaml::parseFile(self::path($compose))['services'];
    }

    private static function file(string $path): string
    {
        return (string) file_get_contents(self::path($path));
    }

    private static function path(string $path): string
    {
        return dirname(__DIR__, 4).'/'.$path;
    }
}
