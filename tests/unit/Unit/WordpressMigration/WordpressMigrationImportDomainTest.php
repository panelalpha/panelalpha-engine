<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\HomeDomainTarget;
use App\System\Project\PhpHosting\WordPress\Migration\ImportDomainCheck;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTargetResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\TargetChoice;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * The test answers the domain an import would get from the site's real home,
 * and a start without `domain` follows that home once resolve_source has
 * found it.
 */
class WordpressMigrationImportDomainTest extends ImportTargetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => ['93.184.216.34'],
        );
    }

    public function test_the_connection_test_answers_the_home_domain_without_www(): void
    {
        Queue::fake();
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpressAt('https://www.WP.com')));

        $this->postJson('/api/wordpress/migrations/test', $this->body())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', 'https://www.WP.com')
            ->assertJsonPath('domain', 'wp.com');

        $this->assertSame(0, WordpressMigration::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_connection_test_answers_domain_taken_for_a_home_on_another_project(): void
    {
        $this->project('owner', 'wp.com');
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpressAt('https://www.wp.com')));

        $this->postJson('/api/wordpress/migrations/test', $this->body())
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_type', SourceCheckFailed::DOMAIN_TAKEN);
    }

    public function test_a_given_domain_is_answered_as_given(): void
    {
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $this->wordpressAt('https://www.wp.com')));

        $this->postJson('/api/wordpress/migrations/test', $this->body(['domain' => 'new.example.org']))
            ->assertOk()
            ->assertJsonPath('domain', 'new.example.org');
    }

    public function test_an_ip_home_answers_domain_required_without_connect_and_no_domain_with_it(): void
    {
        $check = new ImportDomainCheck(new MigrationTargetResolver(static fn (): bool => true));
        $site = new SourceSite('http://93.184.216.34', null, SourceSite::REST_WP_JSON);
        $access = new SourceAccess(url: 'http://93.184.216.34');

        $this->assertSame(['domain' => null], $check->check($access, $site, new TargetChoice()));

        $this->withoutConnect();
        try {
            $check->check($access, $site, new TargetChoice());
            $this->fail('Expected domain_required');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::DOMAIN_REQUIRED, $e->errorType());
        }
    }

    public function test_a_start_without_domain_follows_a_home_that_differs_from_the_url(): void
    {
        $migration = $this->startedFrom('https://blog.example.net', MigrationTarget::newProject('blog.example.net', null));
        $migration->storeResolvedSource('https://www.wp.com', null, SourceSite::REST_WP_JSON);

        (new HomeDomainTarget())->follow($migration);

        $this->assertSame('wp.com', $migration->fresh()->target_label);
    }

    public function test_a_taken_home_domain_fails_the_import_and_keeps_the_target(): void
    {
        $this->project('owner', 'wp.com');
        $migration = $this->startedFrom('https://blog.example.net', MigrationTarget::newProject('blog.example.net', null));
        $migration->storeResolvedSource('https://wp.com', null, SourceSite::REST_WP_JSON);

        try {
            (new HomeDomainTarget())->follow($migration);
            $this->fail('Expected the taken domain to fail the import');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wp.com', $e->getMessage());
        }

        $this->assertSame('blog.example.net', $migration->fresh()->target_label);
    }

    public function test_a_given_domain_or_an_existing_project_is_never_moved(): void
    {
        $given = $this->startedFrom('https://wp.com', (new MigrationTargetResolver())->resolve('https://wp.com', 'shop.example.org'));
        $given->storeResolvedSource('https://www.other.com', null, SourceSite::REST_WP_JSON);
        $this->project('shop', 'shop.example.com');
        $existing = $this->startedFrom('https://wp.com', MigrationTarget::existingProject('shop', 'shop.example.com'));
        $existing->storeResolvedSource('https://www.other.com', null, SourceSite::REST_WP_JSON);

        (new HomeDomainTarget())->follow($given);
        (new HomeDomainTarget())->follow($existing);

        $this->assertTrue($given->fresh()->domain_given);
        $this->assertSame('shop.example.org', $given->fresh()->target_label);
        $this->assertSame('shop.example.com', $existing->fresh()->target_label);
    }

    public function test_an_ip_url_of_a_named_site_takes_the_home_domain(): void
    {
        $migration = $this->startedFrom('http://93.184.216.34', MigrationTarget::newProject(null, null));
        $migration->storeResolvedSource('https://wp.com', null, SourceSite::REST_WP_JSON);

        (new HomeDomainTarget())->follow($migration);

        $this->assertSame('wp.com', $migration->fresh()->target_label);
    }

    private function startedFrom(string $url, MigrationTarget $target): WordpressMigration
    {
        return WordpressMigration::start($url, 'admin', 'secret', $target);
    }

    private function wordpressAt(string $home): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('GET', 'https://www.wp.com/wp-login.php', RoutedSourceHttpClient::loginPage($home, 'https://www.wp.com/wp-login.php'))
            ->on('GET', $home . '/wp-json/', RoutedSourceHttpClient::restIndex($home))
            ->on('POST', 'https://www.wp.com/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', $home . '/wp-admin', RoutedSourceHttpClient::admin());
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + ['url' => 'https://www.wp.com', 'username' => 'admin', 'password' => 'secret'];
    }
}
