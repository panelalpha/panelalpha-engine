<?php

namespace Tests\Integration\Console;

use App\Models\Domain;
use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class WpConfigRestrictCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const HOME = '/srv/homes/alice';

    /** @var \ArrayObject<int, list<string>> */
    private \ArrayObject $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->commands = new \ArrayObject();
    }

    public function test_each_domain_s_wp_config_loses_group_and_other_as_the_account_without_following_a_link(): void
    {
        $this->app->instance(System::class, $this->fakeSystem([
            self::HOME . '/alice.test/public_html/wp-config.php' => 0,
            self::HOME . '/alice.test/wp-config.php' => 1,
            self::HOME . '/public_html/wp-config.php' => Privileged::REFUSED,
        ]));
        $alice = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1002], 'alice.test');
        $this->domain($alice->id, 'alice.test', '/alice.test/public_html');
        $this->domain($alice->id, 'www.alice.test', '/alice.test/public_html');
        $this->domain($alice->id, 'shop.alice.test', '/public_html');

        $exit = Artisan::call('projects:wp-config:restrict', ['--project' => 'alice']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $account = Privileged::accountFiles(1001, 1002);
        $this->assertSame([
            $account->changeMode('go=', self::HOME . '/alice.test/public_html/wp-config.php'),
            $account->changeMode('go=', self::HOME . '/alice.test/wp-config.php'),
            $account->changeMode('go=', self::HOME . '/public_html/wp-config.php'),
        ], $this->commands->getArrayCopy());
        $this->assertStringContainsString("Restricting the wp-config.php files of 'alice'...", $output);
        $this->assertStringContainsString("1 wp-config.php file(s) readable by the project's own user only.", $output);
    }

    public function test_the_broker_changes_the_mode_without_following_a_link(): void
    {
        $actions = (string) file_get_contents(dirname(__DIR__, 4) . '/dockerfiles/root-broker/rootbroker/actions/account.py');

        $this->assertMatchesRegularExpression("/^    'changeMode': as_account\\(\\s*\\(plain\\(values\\.mode\\), every_path\\(NOFOLLOW, HOME\\)\\)/m", $actions);
    }

    public function test_a_container_project_is_left_alone_without_failing_the_run(): void
    {
        $this->app->instance(System::class, $this->fakeSystem([]));
        $bob = $this->makeUser('bob', ['template' => 'dind', 'UID' => 1003, 'GID' => 1003], 'bob.test');
        $this->domain($bob->id, 'bob.test', '/bob.test/public_html');

        $exit = Artisan::call('projects:wp-config:restrict', ['--all' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Not a PHP hosting project; nothing to restrict.', Artisan::output());
        $this->assertSame([], $this->commands->getArrayCopy());
    }

    public function test_the_update_runs_it_for_every_project_without_failing_on_it(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/installer.sh');

        $this->assertMatchesRegularExpression(
            '~exec -T core php artisan projects:wp-config:restrict --all \|\|\s+echo_warning "[^"]+"~',
            $installer
        );
    }

    private function domain(int $userId, string $name, string $documentRoot): void
    {
        Domain::create(['user_id' => $userId, 'domain' => $name, 'type' => 'addon', 'details' => ['document_root' => $documentRoot]]);
    }

    /** @param array<string, int> $exitCodes chmod exit code by path */
    private function fakeSystem(array $exitCodes): System
    {
        return new class ($exitCodes, $this->commands) extends System {
            /**
             * @param array<string, int> $exitCodes
             * @param \ArrayObject<int, list<string>> $commands
             */
            public function __construct(private array $exitCodes, private \ArrayObject $commands)
            {
            }

            public function homesDirPath(): string
            {
                return '/srv/homes';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = (array) $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $this->commands[] = $argv;
                $process = new Process(['sh', '-c', 'exit ' . ($this->exitCodes[(string) end($argv)] ?? 1)]);
                $process->run();

                return $process;
            }
        };
    }
}
