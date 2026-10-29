<?php

namespace Tests\Unit\Hub;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The one address a connection kept before becomes the first of its set, and back. */
class CallerAddressesMigrationTest extends TestCase
{
    private object $migration;

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

        Schema::create('hub_connections', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->string('caller_ip', 45)->nullable();
        });

        $this->migration = require base_path(
            'database/migrations/2026_10_26_100000_keep_several_caller_addresses_on_hub_connections_table.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hub_connections');
        parent::tearDown();
    }

    public function test_the_kept_address_moves_into_the_set_and_back(): void
    {
        DB::table('hub_connections')->insert([
            ['ip' => TestNetwork::public(10), 'caller_ip' => TestNetwork::public3(31)],
            ['ip' => TestNetwork::public(10), 'caller_ip' => null],
        ]);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('hub_connections', 'caller_ip'));
        $sets = DB::table('hub_connections')->orderBy('id')->pluck('caller_ips')->all();
        $this->assertSame([TestNetwork::public3(31)], array_keys(json_decode((string) $sets[0], true)));
        $this->assertNull($sets[1]);

        DB::table('hub_connections')->where('id', 1)->update(['caller_ips' => json_encode([
            TestNetwork::public2(20) => '2026-10-26T10:00:00Z',
            TestNetwork::public3(31) => '2026-10-26T11:00:00Z',
        ])]);
        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('hub_connections', 'caller_ips'));
        $this->assertSame([TestNetwork::public3(31), null], DB::table('hub_connections')->orderBy('id')->pluck('caller_ip')->all());
    }
}
