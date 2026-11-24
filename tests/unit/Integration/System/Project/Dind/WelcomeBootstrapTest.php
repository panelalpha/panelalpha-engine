<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\WelcomeBootstrap;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** An account with no sources and no repository gets a static nginx compose and a welcome page. */
class WelcomeBootstrapTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: ?string, 3: ?string}> */
    private array $written = [];

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<string> */
    private array $existing = [];

    /** @var array<string, string> */
    private array $contents = [];

    /** @var list<string> */
    private array $symlinks = [];

    public function test_an_empty_account_gets_the_static_site_and_the_welcome_page(): void
    {
        $this->bootstrap(hasGitProject: false)->writeIfNeeded();

        $this->assertSame([Privileged::scripts()->makeHomeEntry('/home/alice/project', '0755', '1001:1002')], $this->commands);
        $this->assertSame([
            ['/home/alice/project/'.EngineArtifacts::RUN_COMPOSE, DeployCompose::staticNginx(), '1001:1002', '644'],
            ['/home/alice/project/'.NginxConfig::FILENAME, NginxConfig::site(null), '1001:1002', '644'],
            ['/home/alice/project/index.html', self::WELCOME_HTML, '1001:1002', '644'],
        ], $this->written);
    }

    public function test_an_account_with_its_own_compose_file_is_left_alone(): void
    {
        $this->existing = ['/home/alice/project/compose.yaml'];

        $this->bootstrap(hasGitProject: false)->writeIfNeeded();

        $this->assertSame([], $this->commands);
        $this->assertSame([], $this->written);
    }

    public function test_an_account_with_a_repository_is_left_alone(): void
    {
        $this->bootstrap(hasGitProject: true)->writeIfNeeded();

        $this->assertSame([], $this->commands);
        $this->assertSame([], $this->written);
    }

    public function test_a_welcome_site_written_with_a_required_env_loads_it_optionally_and_keeps_the_rest(): void
    {
        $this->existingRunFile(self::REQUIRED_ENV_RUN_FILE);

        $this->bootstrap(hasGitProject: false)->refreshRunFile();

        $this->assertCount(1, $this->written);
        [$path, $yaml, $owner, $mode] = $this->written[0];
        $this->assertSame(['/home/alice/project/'.EngineArtifacts::RUN_COMPOSE, '1001:1002', '644'], [$path, $owner, $mode]);
        $app = Yaml::parse($yaml)['services']['app'];
        $this->assertSame([['path' => '.env', 'required' => false]], $app['env_file']);
        $this->assertSame(['GREETING' => 'hello'], $app['environment']);
        $this->assertSame(['8080:80'], $app['ports']);
        $this->assertSame(['panelalpha.generated' => 'static-bootstrap'], $app['labels']);
    }

    public function test_a_welcome_site_written_as_it_is_now_is_left_alone(): void
    {
        $this->existingRunFile(DeployCompose::staticNginx());

        $this->bootstrap(hasGitProject: false)->refreshRunFile();

        $this->assertSame([], $this->written);
    }

    public function test_a_run_file_that_is_not_the_welcome_site_is_left_alone(): void
    {
        $this->existingRunFile(str_replace('static-bootstrap', 'dockerfile', self::REQUIRED_ENV_RUN_FILE));

        $this->bootstrap(hasGitProject: false)->refreshRunFile();

        $this->assertSame([], $this->written);
    }

    public function test_a_run_file_replaced_by_a_link_is_not_followed(): void
    {
        $this->existingRunFile(self::REQUIRED_ENV_RUN_FILE);
        $this->symlinks = ['/home/alice/project/'.EngineArtifacts::RUN_COMPOSE];

        $this->bootstrap(hasGitProject: false)->refreshRunFile();

        $this->assertSame([], $this->written);
    }

    private function existingRunFile(string $yaml): void
    {
        $path = '/home/alice/project/'.EngineArtifacts::RUN_COMPOSE;
        $this->existing[] = $path;
        $this->contents[$path] = $yaml;
    }

    private function bootstrap(bool $hasGitProject): WelcomeBootstrap
    {
        $user = $this->createStub(User::class);
        $user->method('hasGitProject')->willReturn($hasGitProject);
        $user->method('linuxIds')->willReturn([1001, 1002]);

        $fs = $this->createStub(Filesystem::class);
        $fs->method('fileExists')->willReturnCallback(fn (string $path): bool => in_array($path, $this->existing, true));
        $fs->method('isSymlink')->willReturnCallback(fn (string $path): bool => in_array($path, $this->symlinks, true));
        $fs->method('fileGetContents')->willReturnCallback(fn (string $path): string => $this->contents[$path] ?? '');
        $fs->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents, ?string $chown = null, ?string $chmod = null): void {
                $this->written[] = [$path, $contents, $chown, $chmod];
            }
        );
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($fs);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->commands[] = (array) $cmd;

            return '';
        });

        $project = $this->createStub(Dind::class);
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('system')->willReturn($system);

        return new WelcomeBootstrap($project, new Paths($project));
    }

    private const REQUIRED_ENV_RUN_FILE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    ports:
      - '8080:80'
    volumes:
      - './:/usr/share/nginx/html/:ro'
      - './panelalpha.nginx.conf:/etc/nginx/conf.d/default.conf:ro'
    env_file:
      - .env
    labels:
      panelalpha.generated: static-bootstrap
    restart: unless-stopped
    environment:
      GREETING: hello

YAML;

    private const WELCOME_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PanelAlpha — Ready</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 80px auto; padding: 0 20px; color: #333; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
        a { color: #0070f3; }
    </style>
</head>
<body>
    <h2>Your environment is ready</h2>
    <p>
        PanelAlpha has set up your container environment. You can now log in and place your
        application files in the <code>~/project</code> directory.
    </p>
    <p>
        When you are ready, create <code>~/project/docker-compose.yml</code> with your
        application's configuration, then start the project again (the <code>up</code> action).
    </p>
</body>
</html>
HTML;
}
