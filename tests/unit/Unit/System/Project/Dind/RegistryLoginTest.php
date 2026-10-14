<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * The logins exist in the account only while a deploy step runs, in a
 * directory of their own, and are gone afterwards whatever happened.
 */
class RegistryLoginTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: ?string, 3: ?string}> */
    private array $written = [];

    /** @var list<list<string>> */
    private array $commands = [];

    public function test_the_config_exists_only_for_the_length_of_the_step(): void
    {
        $login = new RegistryLogin($this->project('ghcr.io acme tok1'));
        $seen = null;

        $login->during(function () use ($login, &$seen): void {
            $seen = $login->configDir();
        });

        $this->assertNotNull($seen);
        $this->assertStringStartsWith('/home/alice/.panelalpha-registry-', $seen);
        $this->assertNull($login->configDir());
        $this->assertSame($seen . '/config.json', $this->written[0][0]);
        $this->assertSame(base64_encode('acme:tok1'), json_decode($this->written[0][1], true)['auths']['ghcr.io']['auth']);
        $this->assertSame(['1000:1000', '600'], [$this->written[0][2], $this->written[0][3]]);
        $this->assertContains(['sudo', 'chmod', '700', $seen], $this->commands);
        $this->assertSame(['sudo', 'rm', '-rf', '--', $seen], end($this->commands));
    }

    public function test_the_config_is_removed_when_the_step_fails(): void
    {
        $login = new RegistryLogin($this->project('ghcr.io acme tok1'));

        try {
            $login->during(static function (): void {
                throw new \RuntimeException('build failed');
            });
            $this->fail('the failure is the caller\'s to see');
        } catch (\RuntimeException $e) {
            $this->assertSame('build failed', $e->getMessage());
        }

        $this->assertNull($login->configDir());
        $this->assertSame(['sudo', 'rm', '-rf', '--'], array_slice(end($this->commands), 0, 4));
    }

    public function test_nested_steps_share_one_config(): void
    {
        $login = new RegistryLogin($this->project('ghcr.io acme tok1'));

        $login->during(fn () => $login->during(fn () => null));

        $this->assertCount(1, $this->written);
        $this->assertCount(1, array_filter($this->commands, static fn (array $c): bool => ($c[1] ?? '') === 'rm'));
    }

    public function test_a_project_without_logins_writes_nothing(): void
    {
        $login = new RegistryLogin($this->project(null));

        $this->assertSame('ran', $login->during(static fn (): string => 'ran'));
        $this->assertSame([], $this->written);
        $this->assertSame([], $this->commands);
    }

    private function project(?string $registryAuth): Dind
    {
        $user = $this->createStub(User::class);
        $user->method('getRegistryAuth')->willReturn($registryAuth);
        $user->method('getChownString')->willReturn('1000:1000');

        $fs = $this->createStub(Filesystem::class);
        $fs->method('filePutContents')->willReturnCallback(
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
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('system')->willReturn($system);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }
}
