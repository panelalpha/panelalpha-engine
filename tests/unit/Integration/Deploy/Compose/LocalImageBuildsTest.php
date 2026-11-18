<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\DockerfileContext;
use App\Lib\Deploy\Compose\LocalImageBuilds;
use App\Lib\Deploy\Compose\LocalImageEvidence;
use PHPUnit\Framework\TestCase;

/**
 * A compose service running a tag only the repository builds, and nothing in the file building it (go-admin's
 * `image: go-admin:latest`), is given the build the repository points at, when no registry has the tag.
 */
class LocalImageBuildsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-local-image-'.bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @param  array<string, string>  $files */
    private function repository(array $files): void
    {
        foreach ($files as $path => $contents) {
            @mkdir(dirname($this->root.'/'.$path), 0777, true);
            file_put_contents($this->root.'/'.$path, $contents);
        }
    }

    /**
     * @param  array<string, mixed>  $services
     * @param  list<string>  $published
     * @return array{compose: array<string, mixed>, built: list<array{service: string, image: string, evidence: string}>}
     */
    private function apply(array $services, array $published = [], string $composeDir = '', ?callable $probe = null): array
    {
        $evidence = new LocalImageEvidence($this->root, $composeDir, [($composeDir === '' ? '' : $composeDir.'/').'docker-compose.yml']);

        return LocalImageBuilds::apply(
            ['services' => $services],
            $evidence,
            [],
            $probe ?? static fn (string $image): bool => in_array($image, $published, true)
        );
    }

    public function test_a_makefile_build_command_naming_the_tag_becomes_its_build(): void
    {
        $this->repository(['Makefile' => "image:\n\tdocker build -t go-admin:latest .\n", 'Dockerfile' => "FROM golang\nCOPY . .\n", 'scripts/Dockerfile' => "FROM alpine\n"]);

        $result = $this->apply(['api' => ['image' => 'go-admin:latest']]);

        $this->assertSame(['context' => '.', 'dockerfile' => 'Dockerfile'], $result['compose']['services']['api']['build']);
        $this->assertSame('go-admin:latest', $result['built'][0]['image']);
        $this->assertStringContainsString('Makefile', $result['built'][0]['evidence']);
    }

    public function test_a_tag_a_registry_publishes_is_left_to_be_pulled(): void
    {
        $this->repository(['README.md' => 'docker build -t myapp .', 'Dockerfile' => "FROM node\n"]);

        $result = $this->apply(['web' => ['image' => 'myapp']], ['myapp:latest']);

        $this->assertArrayNotHasKey('build', $result['compose']['services']['web']);
        $this->assertSame([], $result['built']);
    }

    public function test_nothing_is_built_when_the_registry_cannot_be_asked(): void
    {
        $this->repository(['README.md' => 'docker build -t myapp .', 'Dockerfile' => "FROM node\n"]);

        $result = $this->apply(['web' => ['image' => 'myapp']], [], '', static fn (string $image): ?bool => null);

        $this->assertSame([], $result['built']);
    }

    public function test_the_only_dockerfile_goes_to_the_one_tag_no_registry_has(): void
    {
        $this->repository(['dockerfile' => "FROM node\nCOPY . .\n"]);

        $result = $this->apply([
            'redis' => ['image' => 'redis:latest'],
            'node' => ['image' => 'scrumblr:latest', 'depends_on' => ['redis']],
        ], ['redis:latest']);

        $this->assertSame(['context' => '.', 'dockerfile' => 'dockerfile'], $result['compose']['services']['node']['build']);
        $this->assertArrayNotHasKey('build', $result['compose']['services']['redis']);
    }

    public function test_two_unpublished_tags_do_not_share_the_only_dockerfile(): void
    {
        $this->repository(['Dockerfile' => "FROM python\n"]);

        $result = $this->apply(['app' => ['image' => 'notes-app'], 'markitdown' => ['image' => 'notes-markitdown']]);

        $this->assertSame([], $result['built']);
    }

    public function test_a_dockerfile_another_service_builds_is_not_the_only_one(): void
    {
        $this->repository(['Dockerfile' => "FROM node\n"]);

        $result = $this->apply([
            'nextjs' => ['build' => ['context' => '.'], 'image' => 'editor:latest'],
            'portfolio' => ['image' => 'portfolio:latest'],
        ]);

        $this->assertSame([], $result['built']);
    }

    public function test_another_compose_file_that_builds_the_tag_lends_its_build(): void
    {
        $this->repository([
            'docker/Dockerfile.oldev' => "FROM python\nCOPY requirements.txt .\n",
            'requirements.txt' => '',
            'compose.override.yaml' => "services:\n  home:\n    image: oldev:latest\n    build:\n      context: .\n      dockerfile: docker/Dockerfile.oldev\n",
        ]);

        $result = $this->apply(['web' => ['image' => 'oldev:latest'], 'covers' => ['image' => 'oldev:latest']]);

        $this->assertSame(['context' => '.', 'dockerfile' => 'docker/Dockerfile.oldev'], $result['compose']['services']['web']['build']);
        $this->assertArrayNotHasKey('build', $result['compose']['services']['covers'], 'one service builds the tag, the others run it');
    }

    public function test_a_nested_compose_files_build_is_rebased_to_the_root(): void
    {
        $this->repository([
            'docker/dev/client/Dockerfile' => "FROM node\n",
            'docker/dev/client/docker-compose.yaml' => "services:\n  c:\n    image: solaris-client-dev\n    build:\n      context: ../../../\n      dockerfile: docker/dev/client/Dockerfile\n",
        ]);

        $result = $this->apply(['client' => ['image' => 'solaris-client-dev']]);

        $this->assertSame(['context' => '.', 'dockerfile' => 'docker/dev/client/Dockerfile'], $result['compose']['services']['client']['build']);
    }

    public function test_two_different_builds_for_one_tag_are_not_chosen_between(): void
    {
        $this->repository([
            'Dockerfile' => "FROM node\n",
            'Dockerfile.prod' => "FROM node\n",
            'README.md' => "docker build -t app .\n\ndocker build -f Dockerfile.prod -t app .\n",
        ]);

        $this->assertSame([], $this->apply(['web' => ['image' => 'app']])['built']);
    }

    public function test_a_dockerfile_named_after_the_service_builds_from_where_its_copies_are(): void
    {
        $this->repository([
            'Dockerfile' => "FROM node\n",
            'tor.Dockerfile' => "FROM alpine\nCOPY torrc /etc/tor/torrc\n",
            'torrc' => '',
        ]);

        $result = $this->apply(['tor' => ['image' => 'dmm-tor:latest'], 'web' => ['image' => 'dmm-prod:latest']]);

        $this->assertSame(['context' => '.', 'dockerfile' => 'tor.Dockerfile'], $result['compose']['services']['tor']['build']);
        $this->assertSame(['context' => '.', 'dockerfile' => 'Dockerfile'], $result['compose']['services']['web']['build'], 'the root Dockerfile is the only one left');
    }

    public function test_a_tag_on_a_named_registry_or_built_in_the_file_is_left_alone(): void
    {
        $this->repository(['Dockerfile' => "FROM node\n", 'README.md' => 'docker build -t ghcr.io/acme/web:local .']);

        $result = $this->apply([
            'web' => ['image' => 'ghcr.io/acme/web:local'],
            'app' => ['image' => 'farmbot_web', 'build' => ['context' => './api']],
            'jobs' => ['image' => 'farmbot_web'],
        ]);

        $this->assertSame([], $result['built']);
    }

    public function test_the_build_context_is_the_directory_holding_what_the_dockerfile_copies(): void
    {
        $this->repository([
            'markitdown/Dockerfile' => "FROM python\nCOPY app.py .\n",
            'markitdown/app.py' => '',
            'docker/Dockerfile.web' => "FROM node\nCOPY package.json ./\nCOPY --from=build /out /out\n",
            'package.json' => '{}',
        ]);

        $this->assertSame($this->root.'/markitdown', DockerfileContext::of($this->root.'/markitdown/Dockerfile', [$this->root.'/markitdown', $this->root]));
        $this->assertSame($this->root, DockerfileContext::of($this->root.'/docker/Dockerfile.web', [$this->root.'/docker', $this->root]));
        $this->assertNull(DockerfileContext::of($this->root.'/markitdown/Dockerfile', [$this->root]));
    }
}
