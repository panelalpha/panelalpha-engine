<?php

namespace Tests\Unit\System\Project\Dind;

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

    private function bootstrap(bool $hasGitProject): WelcomeBootstrap
    {
        $user = $this->createStub(User::class);
        $user->method('hasGitProject')->willReturn($hasGitProject);
        $user->method('linuxIds')->willReturn([1001, 1002]);

        $fs = $this->createStub(Filesystem::class);
        $fs->method('fileExists')->willReturnCallback(fn (string $path): bool => in_array($path, $this->existing, true));
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
