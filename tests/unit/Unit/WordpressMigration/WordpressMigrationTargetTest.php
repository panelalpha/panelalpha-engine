<?php

namespace Tests\Unit\WordpressMigration;

use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTargetResolver;
use Tests\Support\TestNetwork;

/**
 * The imported site keeps its own domain: the default is the source host
 * without www., a taken one is domain_taken, an IP source needs Connect or a
 * domain, and an existing project must be classic and empty.
 */
class WordpressMigrationTargetTest extends ImportTargetTestCase
{
    public function test_the_default_domain_is_the_source_host_without_www(): void
    {
        $this->assertSame('example.com', MigrationTargetResolver::defaultDomain('https://WWW.Example.com/blog'));
        $this->assertSame('wp.com', MigrationTargetResolver::defaultDomain('https://wp.com'));
        $this->assertSame('shop.example.com', MigrationTargetResolver::defaultDomain('http://shop.example.com/'));
        $this->assertNull(MigrationTargetResolver::defaultDomain('http://' . TestNetwork::public(50) . '/'));
        $this->assertNull(MigrationTargetResolver::defaultDomain('http://[2001:db8::1]/'));
    }

    public function test_a_new_project_keeps_the_source_domain(): void
    {
        $target = $this->resolver()->resolve('https://www.example.com/blog');

        $this->assertSame('example.com', $target->domain);
        $this->assertNull($target->project);
        $this->assertTrue($target->createsProject);
    }

    public function test_a_given_domain_wins_over_the_source_host(): void
    {
        $target = $this->resolver()->resolve('https://example.com', ' New.Example.org ', projectName: 'newsite');

        $this->assertSame('new.example.org', $target->domain);
        $this->assertSame('newsite', $target->project);
    }

    public function test_a_domain_of_another_project_is_domain_taken(): void
    {
        $this->project('owner', 'example.com');

        $this->assertProblem('domain_taken', fn () => $this->resolver()->resolve('https://www.example.com'));
    }

    public function test_a_domain_an_unfinished_import_holds_is_domain_taken(): void
    {
        WordpressMigration::start('https://example.com', 'admin', 'pw', MigrationTarget::newProject('example.com', null));

        $this->assertProblem('domain_taken', fn () => $this->resolver()->resolve('https://example.com'));
    }

    public function test_an_ip_source_with_connect_gets_an_allocated_name(): void
    {
        $target = $this->resolver()->resolve('http://' . TestNetwork::public2(20) . '/');

        $this->assertNull($target->domain);
        $this->assertTrue($target->createsProject);
    }

    public function test_an_ip_source_without_connect_is_domain_required(): void
    {
        $this->withoutConnect();

        $this->assertProblem('domain_required', fn () => $this->resolver()->resolve('http://' . TestNetwork::public2(20) . '/'));
    }

    public function test_an_ip_source_with_a_domain_needs_no_connect(): void
    {
        $this->withoutConnect();

        $this->assertSame('example.com', $this->resolver()->resolve('http://' . TestNetwork::public2(20) . '/', 'example.com')->domain);
    }

    public function test_an_empty_classic_project_is_imported_into_on_its_own_domain(): void
    {
        $this->project('shop', 'shop.example.com');
        $seen = [];
        $resolver = new MigrationTargetResolver(function (User $user, Domain $domain, string $subdir) use (&$seen): bool {
            $seen = [$user->username, $domain->domain, $subdir];

            return true;
        });

        $target = $resolver->resolve('https://old.example.com/blog', project: 'shop');

        $this->assertSame('shop.example.com', $target->domain);
        $this->assertSame('shop', $target->project);
        $this->assertFalse($target->createsProject);
        $this->assertSame(['shop', 'shop.example.com', 'blog'], $seen);
    }

    public function test_an_existing_project_keeps_its_own_domain(): void
    {
        $this->project('shop', 'shop.example.com');

        $this->assertSame(
            'shop.example.com',
            $this->resolver()->resolve('https://old.example.com', 'shop.example.com', 'shop')->domain,
        );
        $this->assertProblem('domain_mismatch', fn () => $this->resolver()->resolve('https://old.example.com', 'other.example.com', 'shop'));
    }

    public function test_an_unknown_project_is_project_not_found(): void
    {
        $this->assertProblem('project_not_found', fn () => $this->resolver()->resolve('https://example.com', project: 'ghost'));
    }

    public function test_a_container_project_is_not_eligible(): void
    {
        $this->project('app', 'app.example.com', ['template' => 'dind']);

        $this->assertProblem('project_not_eligible', fn () => $this->resolver()->resolve('https://example.com', project: 'app'));
    }

    public function test_a_project_with_a_site_is_not_empty(): void
    {
        $this->project('shop', 'shop.example.com');
        $resolver = new MigrationTargetResolver(static fn (): bool => false);

        $this->assertProblem('project_not_empty', fn () => $resolver->resolve('https://example.com', project: 'shop'));
    }

    public function test_a_taken_project_name_is_name_taken(): void
    {
        $this->project('examplecom', 'other.example.org');

        $this->assertProblem('name_taken', fn () => $this->resolver()->resolve('https://example.com', projectName: 'examplecom'));
    }

    private function resolver(): MigrationTargetResolver
    {
        return new MigrationTargetResolver(static fn (): bool => true);
    }

    private function assertProblem(string $code, callable $resolve): void
    {
        try {
            $resolve();
            $this->fail("Expected the problem {$code}");
        } catch (ProblemException $e) {
            $this->assertSame($code, $e->problems[0]['code'] ?? null);
            $this->assertSame(422, $e->status);
        }
    }
}
