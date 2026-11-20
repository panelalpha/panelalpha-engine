<?php

namespace Tests\Integration\Domains;

use App\Exceptions\ProblemException;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A main-domain rename from a project without a domain row, and a rename that fails part way: the error that failed it
 * reaches the caller, and whatever cannot be put back is logged rather than thrown over it.
 */
class MainDomainRenameRecoveryTest extends TestCase
{
    use InMemoryDatabase;

    /** @var array<string, ProjectDomain&MockInterface> */
    private array $vhosts = [];

    /** @var list<string|list<string>> what reached the host, which a rename never should */
    public array $hostCommands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => TestNetwork::public(10)]);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        $this->app->instance(System::class, $this->hostWithVhosts());
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->hostCommands);
        Setting::clearRuntimeSettings();
        Domain::flushEventListeners();
        parent::tearDown();
    }

    public function test_a_project_without_a_domain_row_gets_one_under_the_new_name(): void
    {
        $user = $this->makeUser('shop', ['template' => 'wordpress'], 'old.example.test');
        $this->vhost('new.example.test')->expects('create')->once();
        $this->vhost('old.example.test')->expects('delete')->once();

        MainDomainRename::apply($user, 'new.example.test');

        $this->assertSame('new.example.test', $user->domain);
        $row = Domain::query()->sole();
        $this->assertSame(['new.example.test', 'main', $user->id], [$row->domain, $row->type, $row->user_id]);
        $this->assertSame('/old.example.test/public_html', $row->getDetails()['document_root'], 'the settings of the old name are carried');
        $this->assertFalse($row->getDetails()['force_https_redirect']);
        $this->assertSame(0, ProxyRule::query()->count(), 'only a container project is routed by rules');
    }

    public function test_a_container_project_without_any_name_is_routed_under_the_new_one(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', 'app_port' => 8080], '');
        $this->vhost('new.example.test')->expects('create')->once();
        $this->vhost('')->expects('delete')->once();

        MainDomainRename::apply($user, 'new.example.test');

        $this->assertSame('new.example.test', Domain::query()->sole()->domain);
        $this->assertSame([80, 443], ProxyRule::query()->where('server_name', 'new.example.test')->orderBy('listen_port')->pluck('listen_port')->map(static fn ($port): int => (int) $port)->all());
        $this->assertArrayNotHasKey('kept_certificate', $user->getDetails());
    }

    public function test_a_validation_answer_from_the_new_vhost_reaches_the_caller_unchanged(): void
    {
        $user = $this->project('old.example.test');
        $refusal = ProblemException::one('domain', 'vhost_refused', 'The webserver refused the name.');
        $this->vhost('new.example.test')->expects('create')->andThrow($refusal);

        try {
            MainDomainRename::apply($user, 'new.example.test');
            $this->fail('the rename went through');
        } catch (ProblemException $e) {
            $this->assertSame($refusal, $e);
        }

        $this->assertSame('old.example.test', User::query()->whereKey($user->id)->value('domain'));
        $this->assertSame(['old.example.test'], Domain::query()->pluck('domain')->all());
    }

    public function test_vhosts_that_cannot_be_put_back_are_logged_and_the_rename_fails(): void
    {
        $user = $this->project('old.example.test');
        $old = $this->vhost('old.example.test');
        $old->expects('delete')->with(true)->once();
        $old->expects('rebuild')->andThrow(new RuntimeException('webserver down'));
        $new = $this->vhost('new.example.test');
        $new->expects('create')->once();
        $new->expects('delete')->andThrow(new RuntimeException('cannot remove'));
        Domain::saving(static function (Domain $domain): void {
            if ($domain->domain === 'new.example.test') {
                throw new RuntimeException('disk full');
            }
        });
        Log::spy();

        $problem = $this->failedRename($user);

        $this->assertSame('domain_rename_failed', $problem->problems[0]['code'] ?? null);
        $this->assertStringContainsString('disk full', $problem->getMessage());
        $this->assertSame(['old.example.test'], Domain::query()->pluck('domain')->all());
        Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => $message === 'Rebuilding the vhost of old.example.test after a failed rename failed: webserver down')->once();
        Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => $message === 'Removing the vhost of new.example.test after a failed rename failed: cannot remove')->once();
    }

    public function test_routing_and_name_that_cannot_be_written_back_are_logged(): void
    {
        $user = $this->project('old.example.test');
        ProxyRule::ensureGeneratedHttpPair('shop', 'old.example.test', 8080);
        $this->vhost('new.example.test')->expects('create')->andReturnUsing(static function (): void {
            DB::statement("CREATE TRIGGER no_rule_delete BEFORE DELETE ON proxy_rules BEGIN SELECT RAISE(ABORT, 'rules locked'); END");
            DB::statement("CREATE TRIGGER no_user_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'users locked'); END");

            throw new RuntimeException('vhost failed');
        });
        Log::spy();

        $problem = $this->failedRename($user);

        $this->assertStringContainsString('vhost failed', $problem->getMessage());
        Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => str_starts_with($message, 'Restoring proxy rules after a failed rename of old.example.test to new.example.test failed:')
            && str_contains($message, 'rules locked'))->once();
        Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => str_starts_with($message, 'Writing the domain old.example.test back after a failed rename to new.example.test failed:')
            && str_contains($message, 'users locked'))->once();
    }

    public function test_a_proxy_that_fails_unexpectedly_does_not_undo_the_rename(): void
    {
        Http::fake(['connect.panelalpha.com/*' => static fn () => throw new RuntimeException('proxy exploded')]);
        $user = $this->project('shop.panelalpha.online');
        Tunnel::create([
            'domain_id' => $user->getMainDomain()->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop.panelalpha.online',
        ]);
        $this->vhost('shop.example.com')->expects('create')->once();
        $this->vhost('shop.panelalpha.online')->expects('delete')->once();
        Log::spy();

        MainDomainRename::apply($user, 'shop.example.com');

        $this->assertSame('shop.example.com', $user->domain);
        $this->assertSame(0, Tunnel::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => $message === 'Could not delete the PanelAlpha Online site shop.panelalpha.online after a rename: proxy exploded')->once();
    }

    private function failedRename(User $user): ProblemException
    {
        try {
            MainDomainRename::apply($user, 'new.example.test');
        } catch (ProblemException $e) {
            return $e;
        }
        $this->fail('the rename went through');
    }

    private function project(string $domain): User
    {
        $user = $this->makeUser('shop', ['template' => 'wordpress'], $domain);
        $this->makeMainDomain($user, $domain);

        return $user;
    }

    private function vhost(string $name): ProjectDomain&MockInterface
    {
        return $this->vhosts[$name] ??= Mockery::mock(ProjectDomain::class);
    }

    private function hostWithVhosts(): System
    {
        $test = $this;

        return new class ($test) extends System {
            public function __construct(private readonly MainDomainRenameRecoveryTest $test) {}

            public function project(User $model): Project
            {
                $project = Mockery::mock(Project::class);
                $project->allows('domain')->andReturnUsing(fn (Domain $domain): ProjectDomain => $this->test->vhostOf($domain));

                return $project;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->test->hostCommands[] = $cmd;

                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                $this->test->hostCommands[] = $cmd;

                return FakeProcess::ok();
            }
        };
    }

    /** The recording vhost of $domain, which answers model() with that row. */
    public function vhostOf(Domain $domain): ProjectDomain
    {
        $vhost = $this->vhost((string) $domain->domain);
        $vhost->allows('model')->andReturn($domain);

        return $vhost;
    }
}
