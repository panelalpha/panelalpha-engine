<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\BindSourceOwners;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Who a service that writes a bind mount runs as, when its image is built here, held already, or only named
 * by the Dockerfile's base: each answer is the uid the paths are handed to, or no hand-over at all.
 */
class BindSourceOwnersBuiltImagesTest extends TestCase
{
    private const ACCOUNT_UID = 4242;

    private string $username = '';

    private string $project = '';

    /** @var list<string> the uid:gid of every hand-over */
    private array $handedTo = [];

    /** @var array<string, array<string, mixed>> image => what the account's daemon holds */
    private array $held = [];

    /** @var list<string> */
    private array $unreadable = [];

    private bool $pullFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'bindimg'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->project = sys_get_temp_dir().'/pa-bind-img-'.bin2hex(random_bytes(4));
        mkdir($this->project.'/data', 0777, true);
        mkdir($this->project.'/app', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->project));
        parent::tearDown();
    }

    public function test_an_explicit_user_is_handed_the_paths_even_without_the_interpolation_env(): void
    {
        $lines = $this->apply("services:\n  web:\n    image: nginx\n    user: \"1000\"\n    volumes:\n      - ./data:/data\n");

        $this->assertSame(['1000:'.self::ACCOUNT_UID], $this->handedTo);
        $this->assertContains('Service web runs as uid 1000 and writes to data through a bind mount; handed them to that uid (the account keeps group write)', $lines);
    }

    public function test_a_service_that_builds_without_naming_an_image_is_left_alone(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM node:22\nUSER 1001\n");

        $this->apply("services:\n  web:\n    build: ./app\n    volumes:\n      - ./data:/data\n");

        $this->assertSame([], $this->handedTo);
    }

    public function test_a_tag_another_service_builds_runs_as_that_dockerfile_s_user(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM node:22\nUSER 1001\n");

        $this->apply("services:\n  builder:\n    build: ./app\n    image: shared\n  runner:\n    image: shared\n    volumes:\n      - ./data:/data\n");

        $this->assertSame(['1001:'.self::ACCOUNT_UID], $this->handedTo);
    }

    public function test_an_unreadable_dockerfile_falls_back_to_the_image_already_held(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM node:22\n");
        $this->unreadable[] = $this->project.'/app/Dockerfile';
        $this->held['acme/web:latest'] = ['Config' => ['User' => 'node']];

        $this->apply("services:\n  web:\n    build: ./app\n    image: acme/web:latest\n    volumes:\n      - ./data:/data\n");

        $this->assertSame(['1000:'.self::ACCOUNT_UID], $this->handedTo);
    }

    public function test_an_image_not_built_yet_runs_as_its_dockerfile_s_base(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM python:3.12\nRUN pip install flask\n");
        $this->held['python:3.12'] = ['Config' => ['User' => '999']];

        $this->apply("services:\n  web:\n    build: ./app\n    image: acme/web:latest\n    volumes:\n      - ./data:/data\n");

        $this->assertSame(['999:'.self::ACCOUNT_UID], $this->handedTo);
    }

    public function test_a_base_that_cannot_be_fetched_hands_nothing_over(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM python:3.12\n");
        $this->pullFails = true;

        $this->apply("services:\n  web:\n    build: ./app\n    image: acme/web:latest\n    volumes:\n      - ./data:/data\n");

        $this->assertSame([], $this->handedTo);
    }

    public function test_a_scratch_image_has_no_user_to_hand_over_to(): void
    {
        file_put_contents($this->project.'/app/Dockerfile', "FROM scratch\nCOPY app /app\n");

        $this->apply("services:\n  web:\n    build: ./app\n    image: acme/web:latest\n    volumes:\n      - ./data:/data\n");

        $this->assertSame([], $this->handedTo);
    }

    /** @return list<string> the deploy log after apply() */
    private function apply(string $compose): array
    {
        $composeFile = $this->project.'/docker-compose.panelalpha.yml';
        file_put_contents($composeFile, $compose);
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $filesystem = $this->createStub(SystemFilesystem::class);
        $filesystem->method('fileExists')->willReturnCallback(static fn (string $path): bool => is_file($path));
        $filesystem->method('directoryExists')->willReturnCallback(static fn (string $path): bool => is_dir($path));
        $filesystem->method('fileGetContents')->willReturnCallback(function (string $path): string {
            if (in_array($path, $this->unreadable, true)) {
                throw new \RuntimeException('Permission denied');
            }

            return (string) file_get_contents($path);
        });
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = array_values((array) $cmd);
            if (LocalRoot::action($argv) === 'account-docker.images') {
                return $this->heldImages(LocalRoot::values($argv));
            }
            if (LocalRoot::action($argv) === 'account-docker.readImageFile') {
                return "root:x:0:0::/root:/bin/sh\nnode:x:1000:1000::/home/node:/bin/sh\n";
            }
            $inner = LocalRoot::inAccount($argv);
            if (($inner[0] ?? null) !== 'sh' || ($inner[1] ?? null) !== '-c') {
                return '';
            }
            $this->handedTo[] = $inner[5];

            return implode("\n", array_slice($inner, 6))."\n";
        });

        $user = $this->createStub(User::class);
        $user->method('getUid')->willReturn(self::ACCOUNT_UID);
        $user->method('getGid')->willReturn(self::ACCOUNT_UID);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensureImage')->willReturnCallback(function (): void {
            if ($this->pullFails) {
                throw new \RuntimeException('pull access denied');
            }
        });

        $environment = $this->createStub(ProjectEnvironment::class);
        $environment->method('forInterpolation')->willThrowException(new \RuntimeException('no .env yet'));

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('userAppComposeFileToRun')->willReturn($composeFile);
        $dind->method('userAppDirPath')->willReturn($this->project);
        $dind->method('environment')->willReturn($environment);
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        (new BindSourceOwners($dind))->apply();

        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }

    /** @param list<string> $asked */
    private function heldImages(array $asked): string
    {
        $held = [];
        foreach ($asked as $image) {
            if (isset($this->held[$image])) {
                $held[] = ['Id' => 'sha256:'.str_repeat('a', 64), 'RepoTags' => [$image]] + $this->held[$image];
            }
        }

        return (string) json_encode($held);
    }
}
