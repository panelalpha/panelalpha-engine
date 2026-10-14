<?php

namespace Tests\Unit\Http;

use App\Lib\Domains\MainDomainRename;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DomainRenameRestoreMetadataTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /** #764: the rule put back after a failed rename keeps its metadata and id. */
    public function test_restored_rule_keeps_metadata(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $rule = ProxyRule::create([
            'owner_scope' => 'user', 'username' => 'alice', 'enabled' => true, 'transport' => 'http',
            'listen_ip' => '*', 'listen_port' => 80, 'server_name' => 'old.test', 'upstream_host' => 'alice',
            'upstream_port' => 3000, 'is_generated' => false, 'metadata' => ['created_via' => 'api'],
        ]);

        try {
            MainDomainRename::apply($alice, 'new.test');
        } catch (\Throwable) {
        }

        $back = ProxyRule::query()->sole();
        $this->assertSame(['old.test', ['created_via' => 'api']], [$back->server_name, $back->metadata]);
    }
}
