<?php

namespace Tests\Unit\Http;

use App\Exceptions\ProblemException;
use App\Http\Middleware\Authenticate;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `PUT /projects/{project}`: what it writes, and the one case of a domain
 * change that must leave the vhost alone. Nothing here touches the host.
 */
class ProjectUpdateTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_limits_settings_and_email_are_written_and_the_rest_left_alone(): void
    {
        $this->makeUser('alice', ['template' => 'wordpress', 'inodes_limit' => 5000, 'bandwidth_limit' => 7]);

        $this->putJson('/api/projects/alice', [
            'email' => 'ops@example.test',
            'disk_space_limit' => 2048,
            'cpu_limit' => 1.5,
            'subdomains_limit' => 3,
            'redis_config' => 'maxmemory 64mb',
        ])->assertOk();

        $user = User::findByUsernameOrFail('alice');
        $this->assertSame('ops@example.test', $user->email);
        $this->assertSame(2048, $user->getDiskSpaceLimit());
        $this->assertSame(1.5, $user->getCpuLimit());
        $this->assertSame(3, $user->getSubdomainsLimit());
        $this->assertSame('maxmemory 64mb', $user->getDetails()['redis_config'] ?? null);
        $this->assertSame(5000, $user->getInodesLimit(), 'not sent, not touched');
        $this->assertSame(7, $user->getBandwidthLimit(), 'not sent, not touched');
    }

    public function test_a_limit_can_be_cleared(): void
    {
        $this->makeUser('alice', ['template' => 'wordpress', 'ftp_accounts_limit' => 4]);

        $this->putJson('/api/projects/alice', ['ftp_accounts_limit' => null])->assertOk();

        $this->assertNull(User::findByUsernameOrFail('alice')->getFtpAccountsLimit());
    }

    /** The same name in another case is not a rename: no vhost rebuilt, nothing stored. */
    public function test_the_same_domain_in_another_case_changes_nothing(): void
    {
        $user = $this->makeUser('alice', ['template' => 'wordpress'], 'shop.example.test');
        $this->makeMainDomain($user, 'shop.example.test');

        $this->putJson('/api/projects/alice', ['domain' => 'SHOP.example.test'])->assertOk();

        $this->assertSame('shop.example.test', User::findByUsernameOrFail('alice')->domain);
        $this->assertSame('shop.example.test', User::findByUsernameOrFail('alice')->getMainDomain()?->domain);
    }

    public function test_the_renamed_main_domain_keeps_its_settings_and_moves_its_www_alias(): void
    {
        $user = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $domain = $this->makeMainDomain($user, 'old.test');
        $domain->setDetails(['aliases' => ['www.old.test'], 'force_https_redirect' => true]);
        $domain->save();

        $renamed = MainDomainRename::replacement($domain, 'new.test');

        $this->assertSame('new.test', $renamed->domain);
        $this->assertSame(['www.new.test'], $renamed->getAliases());
        $this->assertTrue($renamed->getDetails()['force_https_redirect']);
        $this->assertSame('/old.test/public_html', $renamed->getDetails()['document_root'], 'the document root does not move');
        $this->assertFalse($renamed->exists, 'built, not saved');
    }

    /** Its other aliases are still held by the old row, which is this same domain. */
    public function test_the_renamed_main_domain_keeps_its_other_aliases(): void
    {
        $user = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $domain = $this->makeMainDomain($user, 'old.test');
        $domain->setDetails(['aliases' => ['www.old.test', 'shop.test']]);
        $domain->save();

        $renamed = MainDomainRename::replacement($domain, 'new.test');

        $this->assertSame(['www.new.test', 'shop.test'], $renamed->getAliases());
    }

    public function test_a_www_alias_another_domain_holds_is_still_refused(): void
    {
        $user = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $domain = $this->makeMainDomain($user, 'old.test');
        $domain->setDetails(['aliases' => ['www.old.test']]);
        $domain->save();
        $other = $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');
        $other->setDetails(['aliases' => ['www.new.test']]);
        $other->save();

        $this->expectExceptionMessage('Domain already exists');
        MainDomainRename::replacement($domain, 'new.test');
    }

    /** The unique users.domain used to refuse it with a 500, after the vhosts had been rewritten. */
    public function test_another_projects_domain_is_refused_with_422_before_anything_changes(): void
    {
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'old.test'), 'old.test');
        $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');

        $this->putJson('/api/projects/alice', ['domain' => 'BOB.test', 'email' => 'ops@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.domain', ['bob.test is already on this engine.'])
            ->assertJsonPath('problems', [[
                'field' => 'domain',
                'code' => 'domain_taken',
                'message' => 'bob.test is already on this engine.',
            ]]);

        $alice = User::findByUsernameOrFail('alice');
        $this->assertSame('old.test', $alice->domain);
        $this->assertSame('old.test', $alice->getMainDomain()?->domain);
        $this->assertNull($alice->email, 'nothing in the request was applied');
        $this->assertSame(['bob.test', 'old.test'], Domain::query()->orderBy('domain')->pluck('domain')->all());
    }

    public function test_an_addon_domain_alias_or_tunnel_of_another_project_is_refused(): void
    {
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'old.test'), 'old.test');
        $bob = $this->makeUser('bob', ['template' => 'wordpress'], 'bob.test');
        $bobMain = $this->makeMainDomain($bob, 'bob.test');
        $bobMain->setDetails(['aliases' => ['www.bob.test', 'bob-alias.test']]);
        $bobMain->save();
        Domain::create(['user_id' => $bob->id, 'domain' => 'shop.test', 'type' => 'addon', 'details' => []]);
        Tunnel::create([
            'domain_id' => $bobMain->id,
            'user_id' => $bob->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'api-bob.panelalpha.test',
        ]);

        foreach (['shop.test', 'bob-alias.test', 'www.bob.test', 'api-bob.panelalpha.test'] as $taken) {
            $this->putJson('/api/projects/alice', ['domain' => $taken])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.code', 'domain_taken')
                ->assertJsonPath('problems.0.message', "{$taken} is already on this engine.");
        }
        $this->assertSame('old.test', User::findByUsernameOrFail('alice')->getMainDomain()?->domain);
    }

    /** replacement() would refuse the www alias with a bare exception, a 500. */
    public function test_a_taken_www_alias_the_rename_would_move_is_refused_with_422(): void
    {
        $alice = $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'old.test'), 'old.test');
        $alice->setDetails(['aliases' => ['www.old.test']]);
        $alice->save();
        $bob = $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');
        $bob->setDetails(['aliases' => ['www.new.test']]);
        $bob->save();

        $this->putJson('/api/projects/alice', ['domain' => 'new.test'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'domain_taken')
            ->assertJsonPath('problems.0.message', 'www.new.test is already on this engine.');
    }

    /** What the main domain holds goes with the rename, so it does not count against it. */
    public function test_names_the_main_domain_holds_itself_are_free(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $main = $this->makeMainDomain($alice, 'old.test');
        $main->setDetails(['aliases' => ['www.old.test', 'shop.test']]);
        $main->save();
        Tunnel::create([
            'domain_id' => $main->id,
            'user_id' => $alice->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'api-old.panelalpha.test',
        ]);
        $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');

        foreach (['new.test', 'old.test', 'shop.test', 'www.old.test', 'api-old.panelalpha.test'] as $free) {
            $this->assertNull(MainDomainRename::takenName($alice, $free), $free);
        }
    }

    /** Two rows, and one vhost file, for one name: the project's own addon is as taken as anyone's. */
    public function test_the_projects_own_addon_domain_is_taken(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        Domain::create(['user_id' => $alice->id, 'domain' => 'shop.test', 'type' => 'addon', 'details' => []]);

        $this->assertSame('shop.test', MainDomainRename::takenName($alice, 'shop.test'));
    }

    /** The main domain holds www.<own alias> itself, so renaming to that alias is not refused for it. */
    public function test_renaming_to_its_own_alias_keeps_the_www_name_it_already_holds(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $main = $this->makeMainDomain($alice, 'old.test');
        $main->setDetails(['aliases' => ['www.old.test', 'other.test', 'www.other.test']]);
        $main->save();

        $this->assertNull(MainDomainRename::takenName($alice, 'other.test'));
        $this->assertSame(['www.other.test', 'other.test'], MainDomainRename::replacement($main, 'other.test')->getAliases());
    }

    /** Its own tunnel is deleted with the old row, so a www. name it serves is not taken either. */
    public function test_a_www_name_its_own_tunnel_serves_goes_with_it(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'old.test');
        $main = $this->makeMainDomain($alice, 'old.test');
        $main->setDetails(['aliases' => ['www.old.test']]);
        $main->save();
        Tunnel::create([
            'domain_id' => $main->id,
            'user_id' => $alice->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'www.new.test',
        ]);

        $this->assertNull(MainDomainRename::takenName($alice, 'new.test'));
        $this->assertSame(['www.new.test'], MainDomainRename::replacement($main, 'new.test')->getAliases());
    }

    /**
     * Another project took the name between validation and the rename: the unique
     * users.domain refuses the claim before any proxy rule or vhost is touched.
     */
    public function test_a_name_taken_after_validation_is_refused_before_anything_is_rewritten(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind'], 'old.test');
        $this->makeMainDomain($alice, 'old.test');
        $rule = ProxyRule::create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => 'old.test',
            'upstream_host' => 'alice',
            'upstream_port' => 3000,
            'upstream_protocol' => 'http',
            'is_generated' => true,
        ]);
        // bob's users row exists, its domain row not yet: the moment a concurrent create commits.
        $this->makeUser('bob', ['template' => 'wordpress'], 'race.test');

        try {
            MainDomainRename::apply($alice, 'race.test');
            $this->fail('the rename went ahead');
        } catch (ProblemException $e) {
            $this->assertSame('domain_taken', $e->problems[0]['code']);
        }

        $this->assertSame('old.test', User::findByUsernameOrFail('alice')->domain);
        $this->assertSame('old.test', $alice->domain);
        $this->assertSame(['old.test'], Domain::query()->pluck('domain')->all());
        $this->assertSame('old.test', $rule->fresh()?->server_name);
    }

    public function test_an_unknown_project_is_still_404(): void
    {
        $this->makeMainDomain($this->makeUser('bob', ['template' => 'wordpress'], 'bob.test'), 'bob.test');

        $this->putJson('/api/projects/nobody', ['domain' => 'bob.test'])->assertNotFound();
    }
}
