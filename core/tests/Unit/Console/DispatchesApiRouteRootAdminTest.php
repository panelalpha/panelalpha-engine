<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * engine#225 / #224: a fresh install has no admins row until a token is
 * minted, and every command that dispatches an API route in-process -- among
 * them `project:create`, which `installer.sh --repo` runs -- died on a
 * TypeError handing Auth::setUser() a null.
 */
class DispatchesApiRouteRootAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2022_09_05_105857_create_admins_table.php'))->up();

        Route::get('/api/__probe-admin', static fn () => response()->json([
            'admin' => Auth::user() instanceof Admin ? Auth::user()->name : null,
        ]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admins');
        parent::tearDown();
    }

    public function test_a_fresh_install_with_no_admin_still_dispatches_as_root(): void
    {
        $this->assertSame(0, Admin::query()->count());

        $command = new class extends Command {
            use DispatchesApiRoute;

            public function call_(string $uri): Response
            {
                return $this->dispatchApiRoute('GET', $uri);
            }
        };
        $response = $command->call_('/__probe-admin');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['admin' => 'root'], json_decode((string) $response->getContent(), true));
        $this->assertSame(1, Admin::query()->count(), 'the root account is created once, as token minting does');
    }
}
