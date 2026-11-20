<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A restore re-restricts every wp-config.php the domains' document roots name, even one the backup holds at 644,
 * as projects:wp-config:restrict does: as the account and never through a link.
 */
class BackupRestoreWpConfigTest extends TestCase
{
    use InMemoryDatabase;

    /** @var \ArrayObject<int, list<string>> */
    private \ArrayObject $ran;

    private string $engineDir;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->ran = new \ArrayObject;
        $this->engineDir = sys_get_temp_dir().'/pa-restore-wp-config-'.bin2hex(random_bytes(4));
        $this->home = $this->engineDir.'/homes/alice';
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->engineDir));
        parent::tearDown();
    }

    public function test_a_restore_that_replaces_the_home_restricts_every_wp_config_once_the_home_is_the_account_s(): void
    {
        $restore = $this->restore(true);

        $this->assertSame('completed', $restore->model()->fresh()->restoreStatus());
        $this->assertRestrictedAfterTheHandOver();
    }

    public function test_a_restore_merged_into_the_home_restricts_them_too(): void
    {
        $restore = $this->restore(false);

        $this->assertSame('completed', $restore->model()->fresh()->restoreStatus());
        $this->assertContains('mergeIntoHome:.restore-'.$restore->model()->id, $restore->calls);
        $this->assertRestrictedAfterTheHandOver();
    }

    private function assertRestrictedAfterTheHandOver(): void
    {
        $account = Privileged::accountFiles(1001, 1002);
        $expected = [
            $account->changeMode('go=', $this->home.'/alice.test/public_html/wp-config.php'),
            $account->changeMode('go=', $this->home.'/alice.test/wp-config.php'),
            $account->changeMode('go=', $this->home.'/public_html/wp-config.php'),
        ];
        $ran = $this->ran->getArrayCopy();
        $restricted = array_values(array_filter($ran, static fn (array $argv): bool => in_array($argv, $expected, true)));
        $this->assertSame($expected, $restricted);

        $handedOver = array_search(Privileged::files()->handTreeToAccount($this->home, 1001, 1002, false), $ran, true);
        $this->assertNotFalse($handedOver, 'the home is handed to the account');
        $this->assertGreaterThan($handedOver, array_search($expected[0], $ran, true));
    }

    private function restore(bool $deleteExistingFiles): BackupDouble
    {
        $user = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1002], 'alice.test');
        $this->domain($user, 'alice.test', '/alice.test/public_html');
        $this->domain($user, 'www.alice.test', '/alice.test/public_html');
        $this->domain($user, 'shop.alice.test', '/public_html');
        $container = BackupContainer::query()->create(['name' => 'local', 'driver' => 'local', 'location' => '/tmp/backups']);
        $storage = new FakeStorage;
        $project = new Project($this->system(), $user);

        $created = new BackupDouble($project, BackupRecord::prepare($user, $container, 'artisan'), $storage);
        $created->run();
        $this->ran->exchangeArray([]);

        $restore = new BackupDouble($project, $created->model()->fresh(['items']), $storage);
        $restore->restore([], [], $deleteExistingFiles);

        return $restore;
    }

    private function domain(User $user, string $name, string $documentRoot): void
    {
        Domain::create(['user_id' => $user->id, 'domain' => $name, 'type' => 'addon', 'details' => ['document_root' => $documentRoot]]);
    }

    private function system(): System
    {
        return new class($this->engineDir, $this->ran) extends System
        {
            /** @param \ArrayObject<int, list<string>> $ran */
            public function __construct(private string $engineDir, private \ArrayObject $ran) {}

            public function engineDirPath(): string
            {
                return $this->engineDir;
            }

            public function homesDirPath(): string
            {
                return $this->engineDir.'/homes';
            }

            public function filesystem(): Filesystem
            {
                return new class($this) extends Filesystem
                {
                    public function assertFreeSpace(int $bytes): void {}
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = (array) $cmd;

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = (array) $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = (array) $cmd;
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };
    }
}
