<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DockerBuildCommand;
use PHPUnit\Framework\TestCase;

/**
 * The `docker build` lines a README or Makefile spells out, read for the tag, the context and the Dockerfile.
 */
class DockerBuildCommandTest extends TestCase
{
    public function test_an_untagged_name_builds_latest_from_the_context(): void
    {
        $builds = DockerBuildCommand::buildsOf("Run `docker build -t go-admin .` first.\n", 'go-admin', 'latest');

        $this->assertSame([['context' => '.', 'dockerfile' => null, 'target' => null, 'line' => 'docker build -t go-admin .']], $builds);
    }

    public function test_the_file_and_target_options_are_read_in_every_spelling(): void
    {
        $builds = DockerBuildCommand::buildsOf(
            "docker buildx build --load --file=docker/Dockerfile --target prod --tag \"proxy:latest\" .; echo done\n",
            'proxy',
            'latest'
        );

        $this->assertSame('docker/Dockerfile', $builds[0]['dockerfile']);
        $this->assertSame('prod', $builds[0]['target']);
        $this->assertSame('.', $builds[0]['context']);
        $this->assertSame('docker buildx build --load --file=docker/Dockerfile --target prod --tag proxy:latest .', $builds[0]['line']);
    }

    public function test_a_tag_a_variable_supplies_matches_and_a_different_tag_does_not(): void
    {
        $text = "build:\n\tdocker build -f docker/Dockerfile -t altmount:$(DOCKER_IMAGE_TAG) .\n"
            ."ci:\n\tdocker build -f docker/Dockerfile.ci -t altmount:ci-latest .\n";

        $builds = DockerBuildCommand::buildsOf($text, 'altmount', 'latest');

        $this->assertCount(1, $builds);
        $this->assertSame('docker/Dockerfile', $builds[0]['dockerfile']);
    }

    public function test_a_continued_command_is_read_as_one(): void
    {
        $builds = DockerBuildCommand::buildsOf("docker build \\\n  --build-arg A=1 \\\n  -t deming:local \\\n  web\n", 'deming', 'local');

        $this->assertSame('web', $builds[0]['context']);
    }

    public function test_a_context_or_dockerfile_a_variable_names_is_no_evidence(): void
    {
        $this->assertSame([], DockerBuildCommand::buildsOf('docker build -f "$DOCKERFILE" -t app:latest .', 'app', 'latest'));
        $this->assertSame([], DockerBuildCommand::buildsOf('docker build -t app:latest $CONTEXT', 'app', 'latest'));
        $this->assertSame([], DockerBuildCommand::buildsOf('docker build -t app:latest - < Dockerfile', 'app', 'latest'));
    }

    public function test_another_name_is_not_matched(): void
    {
        $this->assertSame([], DockerBuildCommand::buildsOf('docker build -t other-app .', 'app', 'latest'));
    }
}
