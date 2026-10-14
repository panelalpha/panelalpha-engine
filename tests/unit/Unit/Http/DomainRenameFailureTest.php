<?php

namespace Tests\Unit\Http;

use App\Lib\Domains\MainDomainRename;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * engine#764: a rename that fails after the project's proxy rules were moved to
 * the new name must put them back. The new domain's create(), on failing, runs
 * its own cleanup, which deletes the project's rules under the new name -- so a
 * retarget back would find nothing, and the DinD project was left unrouted
 * until its next deploy.
 *
 * The failure is real here: create() reaches the certificate step, where the
 * test container's `sudo` has no terminal. The SQL/database state is what is
 * asserted; the host work create() did before failing runs against tmp paths.
 */
class DomainRenameFailureTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_failed_rename_restores_the_proxy_rules_under_the_old_name(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $this->hold('alice', 'old.test', 80);
        $this->hold('alice', 'old.test', 443);

        try {
            MainDomainRename::apply($alice, 'new.test');
            $this->fail('the rename went ahead');
        } catch (\Throwable) {
            // The create failed; the rename is expected to fail with it.
        }

        $this->assertSame('old.test', User::findByUsernameOrFail('alice')->domain);
        $this->assertSame(['old.test'], DomainModel::query()->pluck('domain')->all());
        $this->assertSame(
            [['old.test', 80], ['old.test', 443]],
            ProxyRule::orderBy('listen_port')->get(['server_name', 'listen_port'])
                ->map(fn (ProxyRule $r): array => [$r->server_name, $r->listen_port])->all(),
            'the project is routed exactly as before'
        );
    }

    /** A rule of another project on the same name is not touched. */
    public function test_a_failed_rename_leaves_another_projects_rule_alone(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $this->hold('alice', 'old.test', 80);
        // A system rule names the same server_name; the rename must not delete it.
        ProxyRule::create([
            'owner_scope' => 'system', 'username' => null, 'enabled' => true,
            'transport' => 'http', 'listen_ip' => '*', 'listen_port' => 8080,
            'server_name' => 'new.test', 'upstream_host' => '10.0.0.5',
            'upstream_port' => 80, 'upstream_protocol' => 'http', 'is_generated' => false,
        ]);

        try {
            MainDomainRename::apply($alice, 'new.test');
        } catch (\Throwable) {
        }

        $this->assertSame('new.test', ProxyRule::query()->where('owner_scope', 'system')->value('server_name'));
    }

    /** Rules that cannot be read could not be put back, so the rename is refused before the name is claimed. */
    public function test_a_rename_whose_proxy_rules_cannot_be_read_changes_nothing(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $this->hold('alice', 'old.test', 80);
        ProxyRule::retrieved(static function (): void {
            throw new \RuntimeException('proxy rules unreadable');
        });
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(update|delete|insert)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        try {
            MainDomainRename::apply($alice, 'new.test');
            $this->fail('the rename went ahead');
        } catch (\RuntimeException $e) {
            $this->assertSame('proxy rules unreadable', $e->getMessage());
        } finally {
            ProxyRule::flushEventListeners();
        }

        $this->assertSame([], $writes);
    }

    private function hold(string $username, string $serverName, int $port): void
    {
        ProxyRule::create([
            'owner_scope' => 'user', 'username' => $username, 'enabled' => true,
            'transport' => 'http', 'listen_ip' => '*', 'listen_port' => $port,
            'server_name' => $serverName, 'upstream_host' => $username,
            'upstream_port' => 3000, 'upstream_protocol' => 'http', 'is_generated' => true,
        ]);
    }
}
