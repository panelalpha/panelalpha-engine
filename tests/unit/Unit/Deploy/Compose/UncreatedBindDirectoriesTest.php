<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\UncreatedBindDirectories;
use PHPUnit\Framework\TestCase;

/** The checkout directories a bind with `create_host_path: false` needs before `up`; files stay the project's. */
class UncreatedBindDirectoriesTest extends TestCase
{
    private const PROJECT = '/home/acct/project';

    /** jm-web, peach and MediaStationGo, as their compose files write them. */
    public function test_directories_the_compose_file_refuses_to_have_docker_create_are_found(): void
    {
        $compose = ComposeYaml::parse(<<<'YAML'
            services:
              jm-web:
                build: .
                user: "1000:1000"
                volumes:
                  - type: bind
                    source: "${JMW_HOST_DATA_DIR:-./data}"
                    target: /app/data
                    bind:
                      create_host_path: false
              peach:
                image: longmeidao/peach:edge
                volumes:
                  - peach-data:/data
                  - type: bind
                    source: ${PEACH_MEDIA_ROOT:-./media}
                    target: /media
                    read_only: true
                    bind:
                      propagation: rslave
                      create_host_path: false
              mediastation:
                image: ghcr.io/shukebta/mediastation-go:latest
                volumes:
                  - ./data:/data
                  - type: bind
                    source: ./downloads
                    target: /downloads
                    bind:
                      create_host_path: false
            YAML);

        $this->assertSame(
            [self::PROJECT . '/data', self::PROJECT . '/media', self::PROJECT . '/downloads'],
            UncreatedBindDirectories::of($compose, self::PROJECT)
        );
    }

    public function test_a_value_the_project_sets_is_followed(): void
    {
        $compose = ['services' => ['app' => ['volumes' => [self::refusing('${DATA_DIR:-./data}', '/data')]]]];

        $this->assertSame(
            [self::PROJECT . '/storage/data'],
            UncreatedBindDirectories::of($compose, self::PROJECT, ['DATA_DIR' => ['./storage/data']])
        );
    }

    public function test_files_binds_docker_may_create_and_paths_outside_the_checkout_are_left_alone(): void
    {
        $compose = ['services' => ['app' => ['volumes' => [
            self::refusing('./certs/cert.pem', '/etc/ssl/cert.pem'),
            self::refusing('./config', '/app/config.json'),
            self::refusing('./.env', '/app/.env'),
            self::refusing('./settings.toml', '/app/settings'),
            self::refusing('../outside', '/x'),
            self::refusing('/srv/media', '/media'),
            self::refusing('./', '/app'),
            self::refusing('~/media', '/media'),
            self::refusing('${UNSET}', '/y'),
            ['type' => 'bind', 'source' => './created', 'target' => '/c'],
            ['type' => 'bind', 'source' => './also', 'target' => '/a', 'bind' => ['create_host_path' => true]],
            ['type' => 'volume', 'source' => 'named', 'target' => '/v', 'bind' => ['create_host_path' => false]],
            './short:/short',
        ]]]];

        $this->assertSame([], UncreatedBindDirectories::of($compose, self::PROJECT));
    }

    public function test_a_hidden_directory_and_a_relative_name_are_directories(): void
    {
        $compose = ['services' => ['app' => ['volumes' => [
            self::refusing('.cache', '/cache'),
            self::refusing('uploads/', '/uploads'),
            self::refusing('./data/../media', '/media'),
        ]]]];

        $this->assertSame(
            [self::PROJECT . '/.cache', self::PROJECT . '/uploads', self::PROJECT . '/media'],
            UncreatedBindDirectories::of($compose, self::PROJECT)
        );
    }

    /** @return array<string, mixed> */
    private static function refusing(string $source, string $target): array
    {
        return ['type' => 'bind', 'source' => $source, 'target' => $target, 'bind' => ['create_host_path' => 'false']];
    }
}
