<?php

namespace Tests\Unit\Console;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * proxy:rule:delete removes the row, then rebuilds and reloads the webserver
 * the way the API does: without that the deleted rule keeps listening.
 */
class ProxyRuleDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private ProxyRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->rule = ProxyRule::query()->create([
            'owner_scope' => 'system',
            'transport' => 'tcp',
            'listen_ip' => '*',
            'listen_port' => 25212,
            'upstream_host' => 'db',
            'upstream_port' => 5432,
            'enabled' => true,
        ]);
    }

    public function test_a_deleted_rule_is_applied(): void
    {
        $this->artisan("proxy:rule:delete {$this->rule->id} --no-interaction --force")
            ->expectsOutputToContain('Rule deleted successfully.')
            ->assertExitCode(0);

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(1);
    }

    public function test_a_cancelled_or_unknown_delete_applies_nothing(): void
    {
        $this->artisan("proxy:rule:delete {$this->rule->id}")
            ->expectsConfirmation('Delete this rule?', 'no')
            ->assertExitCode(0);
        $this->artisan('proxy:rule:delete 999999 --force')
            ->expectsOutputToContain('Rule with ID 999999 not found.')
            ->assertExitCode(1);

        $this->assertSame(1, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }
}
