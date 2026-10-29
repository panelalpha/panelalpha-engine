<?php

namespace Tests\Unit\Console;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * A command that asks before a change, run where it cannot ask: confirm()
 * answered no there, so each printed "Cancelled." and exited 0 with nothing
 * changed, which a script reads as success.
 */
class UnconfirmedChangeTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private const REFUSAL = "This change needs confirmation: pass --force to apply it without a prompt.\n";

    private const TABLES = ['users', 'domains', 'proxy_rules', 'tunnels', 'backup_containers', 'backups', 'settings'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->seedTargets();
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function changes(): array
    {
        return [
            'project setting set' => ['project:settings:set', ['key' => 'protected', 'value' => 'on', '--project' => 'alice']],
            'project setting unset' => ['project:settings:unset', ['key' => 'protected', '--project' => 'bob']],
            'project limits' => ['project:limit:set', ['--project' => 'alice', '--memory-limit' => '512']],
            'domain create' => ['domain:create', ['domain' => 'new.test', '--project' => 'alice']],
            'domain delete' => ['domain:delete', ['domain' => 'alice.test', '--project' => 'alice']],
            'domain set proxy' => ['domain:set-proxy', ['--domain' => 'alice.test', '--proxy-to' => '9090']],
            'domain unset proxy' => ['domain:unset-proxy', ['--domain' => 'alice.test']],
            'tunnel delete' => ['domain:tunnel:delete', ['hostname' => 'app.example.com', '--project' => 'alice']],
            'proxy rule create' => ['proxy:rule:create', [
                '--scope' => 'system', '--transport' => 'tcp', '--listen-port' => '5432',
                '--upstream-host' => 'db', '--upstream-port' => '5432',
            ]],
            'proxy rule update' => ['proxy:rule:update', ['id' => '1', '--upstream-port' => '8081']],
            'proxy rule delete' => ['proxy:rule:delete', ['id' => '1']],
            'backup container create' => ['backup:container:create', [
                '--name' => 'other', '--driver' => 'local', '--location' => '/opt/panelalpha/backups/other',
            ]],
            'backup container update' => ['backup:container:update', ['container' => 'box', '--location' => '/opt/panelalpha/backups/moved']],
            'backup container delete' => ['backup:container:delete', ['container' => 'spare']],
            'project backup delete' => ['project:backup:delete', ['project' => 'alice', 'backup' => '1']],
            'project backup restore' => ['project:backup:restore', ['project' => 'alice', 'backup' => '1']],
            'example domain delete' => ['system:example:delete', []],
        ];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('changes')]
    public function test_without_a_terminal_or_force_the_change_is_refused_and_nothing_is_written(string $command, array $input): void
    {
        $before = $this->snapshot();

        $tester = $this->runWithoutTerminal($command, $input);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(self::REFUSAL, $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame($before, $this->snapshot());
        $this->assertWebserverApplied(0);
    }

    public function test_force_still_applies_the_change_without_a_prompt(): void
    {
        $tester = $this->runWithoutTerminal('project:settings:set', [
            'key' => 'protected', 'value' => 'on', '--project' => 'alice', '--force' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertTrue(User::findByUsername('alice')->isProtected());
    }

    public function test_a_prompt_still_describes_the_change_before_it_asks_and_a_no_cancels_it(): void
    {
        $this->artisan('project:settings:set protected on --project=alice')
            ->expectsOutput("About to set project setting 'protected' for 'alice'.")
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertFalse(User::findByUsername('alice')->isProtected());
    }

    public function test_a_refusal_found_before_the_prompt_still_comes_first(): void
    {
        $tester = $this->runWithoutTerminal('project:settings:set', ['key' => 'protected', 'value' => 'on', '--project' => 'nobody']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("Project 'nobody' not found.", $tester->getDisplay());
        $this->assertSame('', $tester->getErrorOutput());
    }

    /** @param array<string, mixed> $input */
    private function runWithoutTerminal(string $command, array $input): CommandTester
    {
        $tester = new CommandTester($this->app->make(Kernel::class)->all()[$command]);
        $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function seedTargets(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind', 'memory_limit' => 256]);
        $domain = $this->makeMainDomain($alice, 'alice.test');
        $this->makeUser('bob', ['template' => 'dind', User::DETAIL_PROTECTED => User::PROTECTED_ON]);

        ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => 'alice.test',
            'upstream_host' => 'alice',
            'upstream_port' => 8080,
            'upstream_protocol' => 'http',
            'enabled' => true,
        ]);
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $alice->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => 'app.example.com',
            'details' => [],
        ]);

        $box = BackupContainer::create(['name' => 'box', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/box', 'credentials' => null]);
        BackupContainer::create(['name' => 'spare', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/spare', 'credentials' => null]);
        $backup = BackupRecord::prepare($alice, $box, 'artisan');
        $backup->setBackupStatus('completed');
        $backup->save();

        $example = $this->makeUser('example', ['template' => 'dind']);
        Domain::query()->create([
            'user_id' => $example->id,
            'domain' => 'example.test',
            'type' => 'main',
            'details' => ['is_example_domain' => true],
        ]);
        Setting::set('example-domain-created', '1');
    }
}
