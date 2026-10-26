<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\MigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\CreateProjectStep;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Adapted contract: HostingUsername::normalize of the domain (or the source
 * host, www. stripped) yields examplecom, suffixed when taken.
 */
class WordpressMigrationDomainTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        parent::tearDown();
    }

    public function test_www_example_com_yields_username_examplecom(): void
    {
        $this->assertSame('examplecom', SourceUrl::projectUsername('https://www.example.com'));
        $this->assertSame('examplecom', SourceUrl::projectUsername('https://www.example.com/blog'));
        $this->assertSame('examplecom', SourceUrl::projectUsername('https://example.com/blog'));
    }

    public function test_url_path_is_install_subdirectory_not_username(): void
    {
        $this->assertSame('blog', SourceUrl::installSubdirectory('https://example.com/blog'));
        $this->assertSame('', SourceUrl::installSubdirectory('https://example.com'));
        $this->assertSame('blog', SourceUrl::installSubdirectory('https://example.com/blog/wp-login.php'));
        $this->assertSame('', SourceUrl::installSubdirectory('https://example.com/index.php'));
    }

    public function test_wp_admin_url_points_at_the_install_root(): void
    {
        $this->assertSame('', SourceUrl::installSubdirectory('https://example.com/wp-admin'));
        $this->assertSame('', SourceUrl::installSubdirectory('https://example.com/wp-admin/'));
        $this->assertSame('', SourceUrl::installSubdirectory('https://example.com/wp-admin/index.php'));
        $this->assertSame('blog', SourceUrl::installSubdirectory('https://example.com/blog/wp-admin'));
        $this->assertSame('blog', SourceUrl::installSubdirectory('https://example.com/blog/wp-admin/index.php'));
        $this->assertSame('blog', SourceUrl::installSubdirectory('https://example.com/blog/'));
    }

    public function test_parent_directory_segments_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        SourceUrl::installSubdirectory('https://example.com/../../tmp/pwn');
    }

    public function test_create_project_for_an_allocated_name_persists_the_online_label(): void
    {
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://www.example.com/blog',
            username: 'wp-admin',
            password: 'secret',
        );

        $creator = new class implements MigrationProjectCreator {
            public function create(string $username, ?string $domain = null): string
            {
                if ($username !== 'examplecom' || $domain !== null) {
                    throw new RuntimeException("Expected examplecom without a domain, got {$username}");
                }

                return 'examplecom-a1b2.panelalpha.online';
            }
        };

        (new CreateProjectStep($creator, usernameAvailable: static fn (): bool => true))->run($migration);

        $migration->refresh();
        $this->assertSame('examplecom-a1b2.panelalpha.online', $migration->target_label);
        $this->assertSame('examplecom', $migration->target_project);
        $this->assertNull($migration->preview_hostname);
        $this->assertSame(
            WordpressMigration::STEP_STATUS_COMPLETED,
            $this->stepStatus($migration, WordpressMigration::STEP_CREATE_PROJECT),
        );
    }

    public function test_an_ip_source_without_a_name_gets_a_fallback_project_name(): void
    {
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://203.0.113.50',
            username: 'admin',
            password: 'secret',
        );

        $creator = new class implements MigrationProjectCreator {
            public ?string $username = null;

            public function create(string $username, ?string $domain = null): string
            {
                $this->username = $username;

                return 'wordpress-a1b2.panelalpha.online';
            }
        };

        (new CreateProjectStep($creator, usernameAvailable: static fn (): bool => true))->run($migration);

        $this->assertSame('wordpress', $creator->username);
    }

    public function test_a_taken_derived_name_gets_a_suffix(): void
    {
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
        );

        $creator = new class implements MigrationProjectCreator {
            public ?string $username = null;

            public function create(string $username, ?string $domain = null): string
            {
                $this->username = $username;

                return $username . '-a1b2.panelalpha.online';
            }
        };

        (new CreateProjectStep(
            $creator,
            usernameAvailable: static fn (string $name): bool => $name !== 'examplecom',
        ))->run($migration);

        $this->assertMatchesRegularExpression('/^examplecom[0-9]{4}$/', (string) $creator->username);
        $this->assertSame($creator->username, $migration->fresh()->target_project);
    }

    private function stepStatus(WordpressMigration $migration, string $name): string
    {
        foreach ($migration->steps as $step) {
            if ($step['name'] === $name) {
                return $step['status'];
            }
        }

        $this->fail("Missing step {$name}");
    }
}
