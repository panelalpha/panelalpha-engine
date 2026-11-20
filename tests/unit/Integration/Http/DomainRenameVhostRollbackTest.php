<?php

namespace Tests\Integration\Http;

use App\Exceptions\ProblemException;
use App\Http\Middleware\Authenticate;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A rename that fails after the new vhost was rendered renders the old one
 * again and drops the new one, so the project stays routed.
 */
class DomainRenameVhostRollbackTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_a_failed_old_vhost_delete_is_logged_and_the_rename_goes_on(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $deletions = [];
        $this->bindVhosts(
            $alice,
            onCreate: static fn (string $name): null => null,
            onDelete: static function (string $name) use (&$deletions): void {
                $deletions[] = $name;
                if ($name === 'old.test') {
                    throw new \RuntimeException('the old vhost is stuck');
                }
            },
            onRebuild: static fn (string $name): null => null,
        );
        Log::spy();

        MainDomainRename::apply($alice, 'new.test');

        $this->assertSame(['old.test'], $deletions);
        $this->assertSame('new.test', User::findByUsernameOrFail('alice')->domain);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'old.test')
                && str_contains($message, 'the old vhost is stuck')
        );
    }

    public function test_a_failure_after_the_new_vhost_is_created_puts_the_vhosts_back(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $calls = [];
        $this->bindVhosts(
            $alice,
            onCreate: static function (string $name) use (&$calls): void {
                $calls[] = ['create', $name];
            },
            onDelete: static function (string $name) use (&$calls): void {
                $calls[] = ['delete', $name];
            },
            onRebuild: static function (string $name) use (&$calls): void {
                $calls[] = ['rebuild', $name];
            },
        );
        DomainModel::creating(static function (): void {
            throw new \RuntimeException('the database is busy');
        });

        try {
            MainDomainRename::apply($alice, 'new.test');
            $this->fail('the rename went ahead');
        } catch (ProblemException $e) {
            $this->assertSame('domain_rename_failed', $e->problems[0]['code']);
        } finally {
            DomainModel::flushEventListeners();
        }

        $this->assertSame(
            [['create', 'new.test'], ['delete', 'old.test'], ['rebuild', 'old.test'], ['delete', 'new.test']],
            $calls,
            'the new vhost is dropped and the old one rendered again'
        );
        $this->assertSame('old.test', User::findByUsernameOrFail('alice')->domain);
        $this->assertSame(['old.test'], DomainModel::query()->pluck('domain')->all(), 'the old row survives the rollback');
    }

    public function test_the_old_vhost_is_rendered_again_once_its_rules_are_back(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        ProxyRule::create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => 'old.test',
            'upstream_host' => 'app',
            'upstream_port' => 8080,
            'upstream_protocol' => 'http',
            'is_generated' => false,
        ]);
        $rulesSeenByRebuild = [];
        $this->bindVhosts(
            $alice,
            onCreate: static fn (string $name): null => null,
            onDelete: static fn (string $name): null => null,
            onRebuild: static function (string $name) use (&$rulesSeenByRebuild): void {
                $rulesSeenByRebuild[$name] = ProxyRule::query()->where('server_name', $name)->count();
            },
        );
        DomainModel::creating(static function (): void {
            throw new \RuntimeException('the database is busy');
        });

        try {
            MainDomainRename::apply($alice, 'new.test');
            $this->fail('the rename went ahead');
        } catch (ProblemException $e) {
            $this->assertSame('domain_rename_failed', $e->problems[0]['code']);
        } finally {
            DomainModel::flushEventListeners();
        }

        $this->assertSame(['old.test' => 1], $rulesSeenByRebuild, 'the old vhost is rendered with its proxy rule');
        $this->assertSame(['old.test'], ProxyRule::query()->pluck('server_name')->all());
    }

    public function test_a_failed_rename_answers_with_the_reason(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');

        $response = $this->putJson('/api/projects/alice', ['domain' => 'new.test']);

        $response->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'domain')
            ->assertJsonPath('problems.0.code', 'domain_rename_failed');
        $this->assertStringContainsString('new.test', (string) $response->json('problems.0.message'));
    }

    /**
     * A System that hands out one fake project whose domain objects record the
     * calls a rename makes, instead of touching a vhost.
     */
    private function bindVhosts(
        User $user,
        callable $onCreate,
        callable $onDelete,
        callable $onRebuild,
    ): void {
        $project = new class (new System(), $user, $onCreate, $onDelete, $onRebuild) extends Project {
            public function __construct(
                System $system,
                User $user,
                private $onCreate,
                private $onDelete,
                private $onRebuild,
            ) {
                parent::__construct($system, $user);
            }

            public function domain(DomainModel $domain): ProjectDomain
            {
                return new class ($this, $domain, $this->onCreate, $this->onDelete, $this->onRebuild) extends ProjectDomain {
                    public function __construct(
                        Project $project,
                        DomainModel $domain,
                        private $onCreate,
                        private $onDelete,
                        private $onRebuild,
                    ) {
                        parent::__construct($project, $domain);
                    }

                    public function create(): void
                    {
                        ($this->onCreate)($this->model()->domain);
                    }

                    public function delete(bool $keepCertificate = false): void
                    {
                        ($this->onDelete)($this->model()->domain);
                    }

                    public function rebuild(): void
                    {
                        ($this->onRebuild)($this->model()->domain);
                    }
                };
            }
        };

        $system = new class ($project) extends System {
            public function __construct(private Project $fakeProject) {}

            public function project(User $model): Project
            {
                return $this->fakeProject;
            }
        };

        $this->app->instance(System::class, $system);
    }
}
