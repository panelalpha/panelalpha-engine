<?php

namespace Tests\Integration\System;

use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\MysqlSsoUser;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Users;
use App\System\Services\PureFtpd;
use App\System\Services\Webserver;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** A delete that meets no failure removes the logins, the databases, the owned rows and the project row. */
class ProjectDestroyRemovesEverythingTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-destroy-' . bin2hex(random_bytes(4));
    }

    public function test_every_login_database_and_row_of_the_project_goes(): void
    {
        $user = $this->makeUser('shop', ['template' => 'default']);
        FtpAccount::query()->create(['user_id' => $user->id, 'user' => 'shop_ftp', 'directory' => '/']);
        FtpAccount::query()->create(['user_id' => $user->id, 'user' => 'shop_gone', 'directory' => '/']);
        MysqlDatabase::query()->create(['user_id' => $user->id, 'database' => 'shop_app']);
        MysqlDatabase::query()->create(['user_id' => $user->id, 'database' => 'shop_gone']);
        MysqlUser::query()->create(['user_id' => $user->id, 'user' => 'shop_u']);
        MysqlSsoUser::query()->create(['user_id' => $user->id, 'username' => 'shop_sso']);

        $this->system()->project($user->refresh())->destroy();

        $this->assertNull(User::findByUsername('shop'));
        $this->assertSame(0, FtpAccount::query()->count());
        $this->assertSame(0, MysqlDatabase::query()->count());
        $this->assertSame(0, MysqlUser::query()->count());
        $this->assertSame(0, MysqlSsoUser::query()->count());
        $this->assertSame([
            'ftp delete shop_ftp',
            'ftp reload',
            'drop database shop_app',
            'drop user shop_u',
            'drop user shop_sso',
            'rebuild domains',
        ], $this->calls);
    }

    public function test_a_project_without_databases_asks_the_database_server_nothing(): void
    {
        $user = $this->makeUser('shop', ['template' => 'default']);

        $this->system()->project($user->refresh())->destroy();

        $this->assertNull(User::findByUsername('shop'));
        $this->assertSame(['rebuild domains'], $this->calls);
    }

    private function system(): System
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('deleteDomainsConfigs', 'deleteDomainsLogsDirs');
        $ftp = Mockery::mock(PureFtpd::class);
        $ftp->shouldReceive('userExists')->andReturnUsing(static fn (string $name): bool => $name === 'shop_ftp');
        $ftp->shouldReceive('deleteUser')->andReturnUsing(function (string $name): void {
            $this->calls[] = "ftp delete {$name}";
        });
        $ftp->shouldReceive('reload')->andReturnUsing(function (): void {
            $this->calls[] = 'ftp reload';
        });
        $databases = Mockery::mock(Databases::class);
        $databases->shouldReceive('databaseExists')->andReturnUsing(static fn (string $name): bool => $name === 'shop_app');
        $databases->shouldReceive('deleteDatabase')->andReturnUsing(function (string $name): void {
            $this->calls[] = "drop database {$name}";
        });
        $users = Mockery::mock(Users::class);
        $users->shouldReceive('userExists')->andReturnTrue();
        $users->shouldReceive('deleteUser')->andReturnUsing(function (string $name): void {
            $this->calls[] = "drop user {$name}";
        });
        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('databases')->andReturn($databases);
        $mysql->shouldReceive('users')->andReturn($users);

        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('webserver')->andReturn($webserver);
        $system->shouldReceive('ftp')->andReturn($ftp);
        $system->shouldReceive('mysql')->andReturn($mysql);
        $system->shouldReceive('projectDirPath')->andReturn($this->root . '/engine');
        $system->shouldReceive('projectHomeDirPath')->andReturn($this->root . '/home');
        $system->shouldReceive('runProcessOnHost')->andReturn(FakeProcess::of(1));
        $system->shouldReceive('runProcess', 'runProcessWithCallbacks')->andReturnUsing(function (array $cmd): Process {
            $this->calls[] = 'host ' . LocalRoot::action($cmd);

            return FakeProcess::ok();
        });
        $system->shouldReceive('exec')->andReturnUsing(function (array $cmd): string {
            $this->calls[] = 'host ' . LocalRoot::action($cmd);

            return '';
        });
        $system->shouldReceive('rebuildDomains')->andReturnUsing(function (): bool {
            $this->calls[] = 'rebuild domains';

            return true;
        });

        return $system;
    }
}
