<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use ArrayObject;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Renaming a main domain to a `www.` name: the name under it, with the `www.` one as an alias,
 * the shape adding a domain creates. A `www.www.` alias is what it must never be.
 */
class DomainRenameWwwTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_a_rename_to_a_www_name_stores_the_name_under_it_with_the_www_alias(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $main = $this->makeMainDomain($alice, 'old.test');
        $main->setDetails(['aliases' => ['www.old.test', 'shop.test']]);
        $main->save();
        $this->bindVhosts($alice, new ArrayObject());

        $this->putJson('/api/projects/alice', ['domain' => 'www.new.test'])->assertOk();

        $renamed = User::findByUsernameOrFail('alice');
        $this->assertSame('new.test', $renamed->domain, 'the name under www is the main domain');
        $this->assertSame('new.test', $renamed->getMainDomain()?->domain);
        $this->assertSame(
            ['www.new.test', 'shop.test'],
            $renamed->getMainDomain()?->getAliases(),
            'the old www alias follows the name, the other aliases are kept, nothing is doubled'
        );
    }

    public function test_a_www_name_an_old_main_domain_has_no_www_alias_for_still_brings_it(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $this->bindVhosts($alice, new ArrayObject());

        $this->putJson('/api/projects/alice', ['domain' => 'www.new.test'])->assertOk();

        $this->assertSame(['www.new.test'], User::findByUsernameOrFail('alice')->getMainDomain()?->getAliases());
    }

    /** The alias the rename would take is checked as the API checks an alias of a domain being added. */
    public function test_a_www_name_another_domain_holds_is_refused_before_the_rename(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $bob = $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');
        $bob->setDetails(['aliases' => ['www.new.test']]);
        $bob->save();
        $this->bindVhosts($alice, new ArrayObject());

        $this->putJson('/api/projects/alice', ['domain' => 'www.new.test'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'domain')
            ->assertJsonPath('problems.0.code', 'domain_taken')
            ->assertJsonPath('problems.0.message', 'www.new.test is already on this engine.');

        $alice = User::findByUsernameOrFail('alice');
        $this->assertSame('old.test', $alice->domain);
        $this->assertSame('old.test', $alice->getMainDomain()?->domain);
    }

    /** A `www.` main domain an earlier rename left behind is repaired by renaming it to the name under it. */
    public function test_the_name_under_a_www_main_domain_is_the_rename_that_repairs_it(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'www.old.test');
        $main = $this->makeMainDomain($alice, 'www.old.test');
        $main->setDetails(['aliases' => ['www.www.old.test']]);
        $main->save();
        $this->bindVhosts($alice, new ArrayObject());

        $this->putJson('/api/projects/alice', ['domain' => 'old.test'])->assertOk();

        $renamed = User::findByUsernameOrFail('alice');
        $this->assertSame('old.test', $renamed->domain);
        $this->assertSame(['www.old.test'], $renamed->getMainDomain()?->getAliases());
    }

    /** A main domain that already is the name under www is not renamed to move it nowhere. */
    public function test_a_www_name_a_project_already_lives_on_changes_nothing(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'new.test');
        $main = $this->makeMainDomain($alice, 'new.test');
        $main->setDetails(['aliases' => ['www.new.test']]);
        $main->save();
        $calls = new ArrayObject();
        $this->bindVhosts($alice, $calls);

        $this->putJson('/api/projects/alice', ['domain' => 'www.new.test'])->assertOk();

        $this->assertSame([], $calls->getArrayCopy(), 'no vhost is rendered again');
        $this->assertSame('new.test', User::findByUsernameOrFail('alice')->getMainDomain()?->domain);
        $this->assertSame(['www.new.test'], User::findByUsernameOrFail('alice')->getMainDomain()?->getAliases());
    }

    public function test_a_www_name_adds_no_www_www_alias_to_the_replacement(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $main = $this->makeMainDomain($alice, 'old.test');
        $main->setDetails(['aliases' => ['www.old.test']]);
        $main->save();

        $replacement = MainDomainRename::replacement($main, 'www.new.test');

        $this->assertSame('new.test', $replacement->domain);
        $this->assertSame(['www.new.test'], $replacement->getAliases());
    }

    /**
     * A System that hands out one fake project whose domain objects record the calls
     * a rename makes, instead of touching a vhost.
     */
    private function bindVhosts(User $user, ArrayObject $calls): void
    {
        $project = new class (new System(), $user, $calls) extends Project {
            public function __construct(System $system, User $user, private ArrayObject $calls)
            {
                parent::__construct($system, $user);
            }

            public function domain(DomainModel $domain): ProjectDomain
            {
                return new class ($this, $domain, $this->calls) extends ProjectDomain {
                    public function __construct(Project $project, DomainModel $domain, private ArrayObject $calls)
                    {
                        parent::__construct($project, $domain);
                    }

                    public function create(): void
                    {
                        $this->calls->append('create '.$this->model()->domain);
                    }

                    public function delete(bool $keepCertificate = false): void
                    {
                        $this->calls->append('delete '.$this->model()->domain);
                    }

                    public function rebuild(): void
                    {
                        $this->calls->append('rebuild '.$this->model()->domain);
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
