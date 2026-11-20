<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System;
use App\System\Services\Sftp;
use App\System\Services\Webserver;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A delete whose account check gets no answer, or an answer that the account is still there, keeps the project row and
 * its files: removing the home for an account that stays leaves the name refused and the files lost.
 */
class ProjectDeleteUnansweredUidTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    private string $projectDir;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootInMemoryDatabase();

        $this->home = sys_get_temp_dir().'/pa-delete-home-'.bin2hex(random_bytes(4));
        $this->projectDir = sys_get_temp_dir().'/pa-delete-project-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0777, true);
        mkdir($this->projectDir, 0777, true);
        file_put_contents($this->home.'/index.php', 'kept');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->home);
        $this->removeTree($this->projectDir);
        parent::tearDown();
    }

    public function test_a_refused_account_check_keeps_the_row_the_account_and_the_home(): void
    {
        $this->makeUser('goneapp');
        $system = $this->system(FakeProcess::of(77, '', "pa-root: refused: the account database is not readable"));

        $failure = $this->deleteFailure($system);

        $this->assertNotNull(User::findByUsername('goneapp'));
        $this->assertDirectoryExists($this->home);
        $this->assertDirectoryExists($this->projectDir);
        $this->assertFileExists($this->home.'/index.php');
        $this->assertNotContains('users.delete', $this->calls);
        $this->assertNotContains('remove home', $this->calls);
        $this->assertNotContains('remove project dir', $this->calls);
        $this->assertStringContainsString("did not answer whether the account 'goneapp' exists", $failure->getMessage());
    }

    public function test_an_account_that_survives_userdel_keeps_the_home_and_the_row(): void
    {
        $this->makeUser('goneapp');
        $system = $this->system(FakeProcess::ok("1004\n"));

        $failure = $this->deleteFailure($system);

        $this->assertContains('users.delete', $this->calls);
        $this->assertNotContains('remove home', $this->calls);
        $this->assertNotContains('remove project dir', $this->calls);
        $this->assertDirectoryExists($this->home);
        $this->assertNotNull(User::findByUsername('goneapp'));
        $this->assertStringContainsString("account 'goneapp' is still on this server", $failure->getMessage());
    }

    /** What stopped the delete; the state it left behind is what the caller asserts. */
    private function deleteFailure(System $system): \Throwable
    {
        try {
            $system->project($this->user())->destroy();
        } catch (\Throwable $failure) {
            return $failure;
        }

        $this->fail('The delete was expected to stop.');
    }

    private function system(Process $uidAnswer): System
    {
        $sftp = Mockery::mock(Sftp::class);
        $sftp->shouldReceive('endSessions');
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('deleteDomainsConfigs', 'deleteDomainsLogsDirs');

        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('sftp')->andReturn($sftp);
        $system->shouldReceive('webserver')->andReturn($webserver);
        $system->shouldReceive('projectDirPath')->andReturn($this->projectDir);
        $system->shouldReceive('projectHomeDirPath')->andReturn($this->home);
        $system->shouldReceive('runProcessOnHost')->andReturn($uidAnswer);
        $system->shouldReceive('execOnHost')->andReturnUsing(function (array $cmd): string {
            $this->calls[] = (string) LocalRoot::action($cmd);

            return '';
        });
        $system->shouldReceive('exec')->andReturnUsing(function (array $cmd): string {
            $this->calls[] = (LocalRoot::values($cmd)[0] ?? '') === $this->projectDir
                ? 'remove project dir'
                : 'remove home';

            return '';
        });

        return $system;
    }

    private function user(): User
    {
        $staging = Mockery::mock(HasOne::class);
        $staging->shouldReceive('exists')->andReturn(false);
        $backups = Mockery::mock(HasMany::class);
        $backups->shouldReceive('with->get')->andReturn(new Collection);
        $domains = Mockery::mock(HasMany::class);
        $domains->shouldReceive('delete')->andReturn(0);
        $sftpAccounts = Mockery::mock(HasMany::class);
        $sftpAccounts->shouldReceive('pluck')->with('username')->andReturn(collect());
        $sftpAccounts->shouldReceive('delete')->andReturn(0);

        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'goneapp';
        $user->email = 'goneapp@example.com';
        $user->setRelation('domains', new Collection);
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $user->shouldReceive('getTemplate')->andReturn('default');
        $user->shouldReceive('stagingUser')->andReturn($staging);
        $user->shouldReceive('backups')->andReturn($backups);
        $user->shouldReceive('domains')->andReturn($domains);
        $user->shouldReceive('sftpAccounts')->andReturn($sftpAccounts);

        return $user;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
