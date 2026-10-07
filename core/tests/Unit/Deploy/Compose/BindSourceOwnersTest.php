<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\BindSourceOwners;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * sE2EEnd's keycloak (uid 1000) writes the bind-mounted ./keycloak/themes
 * directory; only single-file binds were handed over, so it hit EACCES.
 */
class BindSourceOwnersTest extends TestCase
{
    private string $username = '';

    private string $root = '';

    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'bindown' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->root = sys_get_temp_dir() . '/pa-bind-' . bin2hex(random_bytes(4));
        $this->project = $this->root . '/project';
        mkdir($this->project . '/keycloak/themes/se2eend', 0755, true);
        file_put_contents($this->project . '/keycloak/themes/se2eend/login.css', 'a{}');
        chmod($this->project . '/keycloak/themes/se2eend/login.css', 0644);
        file_put_contents($this->project . '/init.sql', 'select 1;');
        chmod($this->project . '/init.sql', 0644);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_directory_and_file_binds_are_handed_to_the_service_uid(): void
    {
        $lines = $this->apply("services:\n  keycloak:\n    image: quay.io/keycloak/keycloak:26.5\n    user: \"1000\"\n"
            . "    volumes:\n      - ./keycloak/themes:/opt/keycloak/themes\n      - ./init.sql:/init.sql\n");

        $this->assertContains(
            'Service keycloak runs as uid 1000 and writes to keycloak/themes, init.sql through a bind mount; '
            . 'handed them to that uid (the account keeps group write)',
            $lines,
            implode("\n", $lines)
        );
        $this->assertSame('0664', $this->mode('/keycloak/themes/se2eend/login.css'));
    }

    public function test_a_directory_bind_never_reaches_outside_the_checkout(): void
    {
        mkdir($this->root . '/outside', 0755);
        file_put_contents($this->root . '/outside/secret', 'x');
        chmod($this->root . '/outside/secret', 0644);
        symlink($this->root . '/outside', $this->project . '/linked');
        symlink($this->root . '/outside', $this->project . '/keycloak/themes/escape');

        $lines = $this->apply("services:\n  keycloak:\n    image: keycloak\n    user: \"1000\"\n"
            . "    volumes:\n      - ./linked:/a\n      - ./keycloak/themes:/b\n      - ./:/c\n");

        $this->assertContains(
            'Service keycloak runs as uid 1000 and writes to keycloak/themes through a bind mount; '
            . 'handed them to that uid (the account keeps group write)',
            $lines
        );
        $this->assertSame('0755', $this->mode('/../outside'));
        $this->assertSame('0644', $this->mode('/../outside/secret'));
    }

    /**
     * kassambara/wordpress-docker-compose: `wpcli` is a tag the file builds, so
     * asking a registry for it only logged `docker.io/library/wpcli:latest: not
     * found`; its user comes from its Dockerfile. `wordpress:${WORDPRESS_VERSION:-latest}`
     * is fetched under the name compose runs, not the raw string.
     */
    public function test_a_tag_the_file_builds_is_never_fetched_and_its_user_comes_from_its_dockerfile(): void
    {
        mkdir($this->project . '/wordpress', 0755);
        mkdir($this->project . '/wpcli', 0755);
        file_put_contents($this->project . '/wpcli/Dockerfile', "FROM wordpress:cli\nUSER root\nRUN apk add make\nUSER 33:33\nCMD [\"wp\", \"shell\"]\n");

        $lines = $this->apply(
            "services:\n  wordpress:\n    image: wordpress:\${WORDPRESS_VERSION:-latest}\n    volumes:\n      - \${WORDPRESS_DATA_DIR:-./wordpress}:/var/www/html\n"
            . "  wpcli:\n    build: ./wpcli/\n    image: wpcli\n    volumes:\n      - \${WORDPRESS_DATA_DIR:-./wordpress}:/var/www/html\n"
        );

        $this->assertSame(['wordpress:latest'], $this->fetched);
        $this->assertContains(
            'Service wpcli runs as uid 33 and writes to wordpress through a bind mount; handed them to that uid (the account keeps group write)',
            $lines,
            implode("\n", $lines)
        );
    }

    /** @var list<string> images ensureImage() was asked for */
    private array $fetched = [];

    /** @return list<string> the deploy log after apply() */
    private function apply(string $compose): array
    {
        $composeFile = $this->root . '/docker-compose.run.yml';
        file_put_contents($composeFile, $compose);
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        // Runs `test` and the hand-over script locally; the owner becomes our own uid, as tests are not root.
        $system = new class () extends System {
            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(array_values(array_diff((array) $cmd, ['sudo'])));
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                $dind = array_search('dind', $cmd, true);
                if ($dind === false) {
                    $cmd = array_values(array_diff($cmd, ['sudo']));
                } else {
                    $cmd = array_slice($cmd, $dind + 1);
                    $cmd[5] = getmyuid() . ':' . getmygid();
                }
                $process = new Process($cmd);
                $process->mustRun();

                return $process->getOutput();
            }
        };

        $user = $this->createStub(User::class);
        $user->method('getUid')->willReturn(4242);
        $user->method('getGid')->willReturn(getmygid());

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('composeFilePath')->willReturn($this->root . '/outer.yml');
        $dind->method('userAppComposeFileToRun')->willReturn($composeFile);
        $dind->method('userAppDirPath')->willReturn($this->project);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensureImage')->willReturnCallback(function (string $image): void {
            $this->fetched[] = $image;
        });
        $dind->method('innerDocker')->willReturn($inner);

        (new BindSourceOwners($dind))->apply();

        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }

    private function mode(string $relative): string
    {
        clearstatcache();

        return sprintf('%04o', fileperms($this->project . $relative) & 0777);
    }
}
