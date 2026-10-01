<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeInclude;
use PHPUnit\Framework\TestCase;

class ComposeIncludeTest extends TestCase
{
    /**
     * @param array<string, string> $files
     * @return callable(string): ?string
     */
    private function reader(array $files): callable
    {
        return static fn (string $relative): ?string => $files[$relative] ?? null;
    }

    public function test_included_services_join_the_file_with_paths_from_the_root(): void
    {
        $read = $this->reader([
            'docker/app.yml' => "include:\n  - db/db.yml\nservices:\n  app:\n    build: ..\n    env_file: ./app.env\nvolumes:\n  data: {}\n",
            'docker/db/db.yml' => "services:\n  db:\n    image: postgres:16\n    volumes:\n      - ./init:/docker-entrypoint-initdb.d\n",
        ]);

        $result = ComposeInclude::flatten(
            ['include' => ['docker/app.yml'], 'services' => ['proxy' => ['image' => 'nginx']]],
            $read
        );
        $compose = $result['compose'];

        $this->assertArrayNotHasKey('include', $compose);
        $this->assertSame(['db', 'app', 'proxy'], array_keys($compose['services']));
        $this->assertSame('.', $compose['services']['app']['build']);
        $this->assertSame('./docker/app.env', $compose['services']['app']['env_file']);
        $this->assertSame(['./docker/db/init:/docker-entrypoint-initdb.d'], $compose['services']['db']['volumes']);
        $this->assertArrayHasKey('data', $compose['volumes']);
        $this->assertCount(2, $result['sources']);
    }

    public function test_the_including_files_own_service_wins_a_name_clash(): void
    {
        $result = ComposeInclude::flatten(
            ['include' => [['path' => 'base.yml']], 'services' => ['app' => ['image' => 'mine']]],
            $this->reader(['base.yml' => "services:\n  app:\n    image: theirs\n"])
        );

        $this->assertSame('mine', $result['compose']['services']['app']['image']);
    }

    public function test_project_directory_is_where_the_included_paths_resolve(): void
    {
        $result = ComposeInclude::flatten(
            ['include' => [['path' => 'compose/web.yml', 'project_directory' => 'web']]],
            $this->reader(['compose/web.yml' => "services:\n  web:\n    build: .\n"])
        );

        $this->assertSame('./web', $result['compose']['services']['web']['build']);
    }

    public function test_an_include_outside_the_project_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComposeInclude::flatten(['include' => ['../elsewhere.yml']], $this->reader(['../elsewhere.yml' => "services: {}\n"]));
    }
}
