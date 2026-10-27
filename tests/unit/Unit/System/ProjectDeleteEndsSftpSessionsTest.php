<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Services\Sftp;
use App\System\Services\Webserver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A project delete ends its SFTP sessions and removes its logins before the
 * OS user and the home go, so an open session cannot keep writing into a home
 * that is being removed and break the delete half way.
 */
class ProjectDeleteEndsSftpSessionsTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        (require base_path('database/migrations/2026_04_23_000000_create_proxy_rules_table.php'))->up();
        (require base_path('database/migrations/2022_09_06_160855_create_domains_table.php'))->up();
        (require base_path('database/migrations/2026_09_07_000000_create_tunnels_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tunnels');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_sftp_sessions_end_and_logins_go_before_the_os_user_and_home_are_removed(): void
    {
        $system = $this->system();
        $user = $this->user();

        try {
            $system->project($user)->destroy();
            $this->fail('The OS user removal was expected to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('userdel', $e->getMessage());
        }

        $this->assertSame(['end sessions', 'delete logins', 'remove os user'], $this->calls);
    }

    private function system(): System
    {
        $sftp = Mockery::mock(Sftp::class);
        $sftp->shouldReceive('endSessions')->once()->with(['goneapp_sftp'])->andReturnUsing(function (): void {
            $this->calls[] = 'end sessions';
        });
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('deleteDomainsConfigs', 'deleteDomainsLogsDirs');

        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('sftp')->andReturn($sftp);
        $system->shouldReceive('webserver')->andReturn($webserver);
        $system->shouldReceive('projectDirPath')->andReturn('/nonexistent/goneapp/project');
        $system->shouldReceive('projectHomeDirPath')->andReturn('/nonexistent/goneapp');
        $system->shouldReceive('isUidExists')->andReturnUsing(function (): bool {
            $this->calls[] = 'remove os user';
            throw new RuntimeException('userdel: user goneapp is currently used by process 4242');
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
        $sftpAccounts->shouldReceive('pluck')->with('username')->andReturn(collect(['goneapp_sftp']));
        $sftpAccounts->shouldReceive('delete')->andReturnUsing(function (): int {
            $this->calls[] = 'delete logins';

            return 1;
        });

        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'goneapp';
        $user->setRelation('domains', new Collection);
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $user->shouldReceive('getTemplate')->andReturn('default');
        $user->shouldReceive('stagingUser')->andReturn($staging);
        $user->shouldReceive('backups')->andReturn($backups);
        $user->shouldReceive('domains')->andReturn($domains);
        $user->shouldReceive('sftpAccounts')->andReturn($sftpAccounts);

        return $user;
    }
}
