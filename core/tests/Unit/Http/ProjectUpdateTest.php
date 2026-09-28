<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\MainDomainRename;
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
}
