<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `up`, and the `up` a pull ends with, build any image the account lacks --
 * after a reclaim pruned it, say -- so they are held as a deploy's build is.
 */
class ContainerOperationsBuildGuardTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_RUNTIME' => 'privileged']);
    }

    /** @param list<string> $expected */
    #[DataProvider('actions')]
    public function test_only_actions_that_can_build_are_guarded(string $action, array $expected): void
    {
        $result = (new ContainerOperations($project = $this->project(), $project->shell()))->projectAction($action);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame($expected, $this->ran);
    }

    public static function actions(): array
    {
        return [
            'up' => ['up', ['build-guard', 'compose up', 'build-guard-release']],
            'pull' => ['pull', ['build-guard', 'compose pull', 'compose up', 'build-guard-release']],
            'restart' => ['restart', ['compose restart']],
            'stop' => ['stop', ['compose stop']],
        ];
    }

    private function project(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = array_values((array) $cmd);
            $guard = array_values(preg_grep('/^build-guard/', $argv) ?: []);
            preg_match("/'compose' '(\\w+)'/", (string) end($argv), $compose);
            $this->ran[] = $guard[0] ?? 'compose ' . ($compose[1] ?? '?');

            return '';
        });

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder('8192m', ''));

        $user = $this->createStub(ModelsUser::class);
        $user->method('effectiveMemoryLimit')->willReturn(1024);

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn('/opt/users/alice/docker-compose.yml');
        $project->method('userModel')->willReturn($user);
        $project->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', ...$rest]
        );
        $shell = new ShellOperations($project);
        $project->method('shell')->willReturn($shell);
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));

        return $project;
    }
}
