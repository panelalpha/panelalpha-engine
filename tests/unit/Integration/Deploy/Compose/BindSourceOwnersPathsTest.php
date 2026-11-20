<?php

namespace Tests\Integration\Deploy\Compose;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\BindSourceOwners;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * BindSourceOwners::apply() with every account command answered by a fake:
 * which services are handed over, to which uid, and what the log says.
 */
class BindSourceOwnersPathsTest extends TestCase
{
    private string $username = '';

    private string $root = '';

    private string $project = '';

    /** @var list<list<string>> commands run inside the account, after `dind`, and requests to its daemon */
    private array $ran = [];

    /** @var list<string> */
    private array $fetched = [];

    /** @var array<string, string> image => its Config.User */
    private array $imageUsers = [];

    /** @var array<string, string> image => its /etc/passwd */
    private array $passwd = [];

    /** @var list<string> images the account already holds */
    private array $local = [];

    /** @var array<string, string> uid:gid => what the hand-over fails with */
    private array $handOverFails = [];

    private bool $handsNothing = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'bindpath' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->root = sys_get_temp_dir() . '/pa-bindpath-' . bin2hex(random_bytes(4));
        $this->project = $this->root . '/project';
        mkdir($this->project . '/data', 0755, true);
        file_put_contents($this->project . '/app.ini', 'x=1');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_an_account_without_a_uid_hands_nothing_over(): void
    {
        $this->assertSame([], $this->apply("services:\n  a:\n    image: busybox\n    user: \"1000\"\n    volumes:\n      - ./data:/d\n", uid: null));
        $this->assertSame([], $this->ran);
    }

    public function test_a_run_file_that_cannot_be_read_hands_nothing_over(): void
    {
        $this->assertSame([], $this->apply(null));
        $this->assertSame([], $this->ran);
    }

    public function test_a_run_file_that_is_not_a_mapping_hands_nothing_over(): void
    {
        $this->assertSame([], $this->apply("just text\n"));
        $this->assertSame([], $this->ran);
    }

    public function test_a_numeric_user_is_handed_its_existing_paths_only(): void
    {
        $lines = $this->apply("services:\n  a:\n    image: busybox\n    user: \"1000:1000\"\n    volumes:\n      - ./data:/d\n      - ./missing:/m\n      - ./app.ini:/etc/app.ini\n");

        $this->assertSame([
            'Service a runs as uid 1000 and writes to data, app.ini through a bind mount; handed them to that uid (the account keeps group write)',
        ], $lines);
        $this->assertSame(['sh', $this->project, '1000:' . getmygid(), $this->project . '/data', $this->project . '/app.ini'], array_slice($this->ran[0], 3));
        $this->assertCount(1, $this->ran);
    }

    public function test_a_service_with_only_missing_paths_is_skipped(): void
    {
        $this->assertSame([], $this->apply("services:\n  a:\n    image: busybox\n    user: \"1000\"\n    volumes:\n      - ./missing:/m\n"));
        $this->assertSame([], $this->ran);
    }

    public function test_a_named_user_is_read_from_the_images_passwd(): void
    {
        $this->passwd['nginx:1'] = "root:x:0:0::/root:/bin/sh\nnginx:x:101:101::/var/cache/nginx:/sbin/nologin\n";

        $lines = $this->apply("services:\n  web:\n    image: nginx:1\n    user: nginx\n    volumes:\n      - ./data:/d\n");

        $this->assertSame([
            'Service web runs as uid 101 and writes to data through a bind mount; handed them to that uid (the account keeps group write)',
        ], $lines);
        $this->assertSame(['account-docker.readImageFile', 'nginx:1', '/etc/passwd'], $this->ran[0]);
        $this->assertCount(2, $this->ran);
    }

    public function test_a_named_user_whose_passwd_cannot_be_read_is_not_resolved(): void
    {
        $this->assertSame([], $this->apply("services:\n  web:\n    image: nginx:1\n    user: nginx\n    volumes:\n      - ./data:/d\n"));
        $this->assertSame([['account-docker.readImageFile', 'nginx:1', '/etc/passwd']], $this->ran);
    }

    public function test_an_image_without_a_user_runs_as_root_and_is_skipped(): void
    {
        $this->imageUsers['postgres:16'] = '';

        $this->assertSame([], $this->apply("services:\n  db:\n    image: postgres:16\n    volumes:\n      - ./data:/var/lib/postgresql/data\n"));
        $this->assertSame(['postgres:16'], $this->fetched);
        $this->assertSame([['account-docker.images', 'postgres:16']], $this->ran);
    }

    public function test_an_image_user_is_handed_its_paths(): void
    {
        $this->imageUsers['grafana:11'] = '472';

        $lines = $this->apply("services:\n  grafana:\n    image: grafana:11\n    volumes:\n      - ./data:/var/lib/grafana\n");

        $this->assertSame([
            'Service grafana runs as uid 472 and writes to data through a bind mount; handed them to that uid (the account keeps group write)',
        ], $lines);
    }

    public function test_a_service_running_as_the_account_is_skipped(): void
    {
        $this->assertSame([], $this->apply("services:\n  a:\n    image: busybox\n    user: \"4242\"\n    volumes:\n      - ./data:/d\n"));
        $this->assertSame([], $this->ran);
    }

    public function test_a_hand_over_that_fails_is_a_warning_and_the_next_service_still_runs(): void
    {
        $this->handOverFails['1000:' . getmygid()] = "chown: permission denied\n";

        $lines = $this->apply("services:\n  a:\n    image: busybox\n    user: \"1000\"\n    volumes:\n      - ./data:/d\n"
            . "  b:\n    image: busybox\n    user: \"1001\"\n    volumes:\n      - ./app.ini:/a.ini\n");

        $this->assertSame([
            'Could not hand the paths service a writes to uid 1000: chown: permission denied',
            'Service b runs as uid 1001 and writes to app.ini through a bind mount; handed them to that uid (the account keeps group write)',
        ], $lines);
    }

    public function test_a_hand_over_that_handed_nothing_says_nothing(): void
    {
        $this->handsNothing = true;

        $this->assertSame([], $this->apply("services:\n  a:\n    image: busybox\n    user: \"1000\"\n    volumes:\n      - ./data:/d\n"));
        $this->assertCount(1, $this->ran);
    }

    public function test_a_named_user_of_an_image_not_built_yet_is_not_resolved(): void
    {
        mkdir($this->project . '/api');
        file_put_contents($this->project . '/api/Dockerfile', "FROM node:22\n");

        $this->assertSame([], $this->apply("services:\n  api:\n    build: ./api\n    image: acme-api\n    user: node\n    volumes:\n      - ./data:/d\n"));
        $this->assertSame([['account-docker.images', 'acme-api:latest']], $this->ran);
    }

    public function test_a_named_user_of_an_image_built_before_reads_its_passwd(): void
    {
        mkdir($this->project . '/api');
        file_put_contents($this->project . '/api/Dockerfile', "FROM node:22\n");
        $this->local = ['acme-api:latest'];
        $this->passwd['acme-api:latest'] = "node:x:1000:1000::/home/node:/bin/sh\n";

        $lines = $this->apply("services:\n  api:\n    build: ./api\n    image: acme-api\n    user: node\n    volumes:\n      - ./data:/d\n");

        $this->assertSame([
            'Service api runs as uid 1000 and writes to data through a bind mount; handed them to that uid (the account keeps group write)',
        ], $lines);
    }

    /** @return list<string> the deploy log after apply() */
    private function apply(?string $compose, ?int $uid = 4242): array
    {
        $composeFile = $this->root . '/docker-compose.run.yml';
        if ($compose !== null) {
            file_put_contents($composeFile, $compose);
        }
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $before = count($logger->entries());

        $system = new class ($this) extends System {
            public function __construct(private BindSourceOwnersPathsTest $test)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this) extends SystemFilesystem {
                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        if (! is_file($path)) {
                            throw new \RuntimeException("cannot read {$path}");
                        }

                        return (string) file_get_contents($path);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                if (LocalRoot::action($cmd) === 'account-docker.images') {
                    return $this->test->inspect(array_slice(LocalRoot::values($cmd), 1));
                }
                if (LocalRoot::action($cmd) === 'account-docker.readImageFile') {
                    return $this->test->readImageFile(...array_slice(LocalRoot::values($cmd), 1));
                }
                $inAccount = LocalRoot::inAccount($cmd);

                return $this->test->answer($inAccount === [] ? array_values($cmd) : $inAccount);
            }
        };

        $user = $this->createStub(User::class);
        $user->method('getUid')->willReturn($uid);
        $user->method('getGid')->willReturn(getmygid());

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('composeFilePath')->willReturn($this->root . '/outer.yml');
        $dind->method('homeDirPath')->willReturn($this->root);
        $dind->method('userAppComposeFileToRun')->willReturn($composeFile);
        $dind->method('userAppDirPath')->willReturn($this->project);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensureImage')->willReturnCallback(function (string $image): void {
            $this->fetched[] = $image;
        });
        $dind->method('innerDocker')->willReturn($inner);

        (new BindSourceOwners($dind))->apply();

        return array_values(array_map(
            static fn (array $entry): string => $entry['msg'],
            array_slice($logger->entries(), $before)
        ));
    }

    /**
     * The daemon's inspect of each image: held when the account had it or fetched it.
     *
     * @param list<string> $images
     */
    public function inspect(array $images): string
    {
        $this->ran[] = ['account-docker.images', ...$images];
        $found = [];
        foreach ($images as $image) {
            $found[] = in_array($image, [...$this->local, ...$this->fetched], true)
                ? ['Id' => 'sha256:' . str_repeat('a', 64), 'Config' => ['User' => $this->imageUsers[$image] ?? '']]
                : null;
        }

        return (string) json_encode($found);
    }

    /** $path of $image, as the daemon reads it from a container created for it; it has no other file. */
    public function readImageFile(string $image, string $path): string
    {
        $this->ran[] = ['account-docker.readImageFile', $image, $path];
        if ($path !== '/etc/passwd' || ! isset($this->passwd[$image])) {
            throw new DockerErrorException("Error response from daemon: Could not find the file {$path} in container");
        }

        return $this->passwd[$image];
    }

    /** @param list<string> $cmd */
    public function answer(array $cmd): string
    {
        $this->ran[] = $cmd;
        if (($cmd[0] ?? null) === 'sh' && str_starts_with($cmd[2] ?? '', 'root=$1')) {
            if (isset($this->handOverFails[$cmd[5]])) {
                throw new \RuntimeException($this->handOverFails[$cmd[5]]);
            }

            return $this->handsNothing ? "\n" : implode("\n", array_slice($cmd, 6)) . "\n";
        }

        throw new \RuntimeException('unexpected command: ' . implode(' ', $cmd));
    }
}
