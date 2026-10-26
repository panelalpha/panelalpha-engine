<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\CreateProjectStep;
use RuntimeException;

/**
 * create_project builds the project on the kept domain with no tunnel, or
 * adopts the caller's project, and opens a panelalpha.online preview named
 * after the domain when the engine has Connect.
 */
class WordpressMigrationPreviewTest extends ImportTargetTestCase
{
    /** @var list<string> */
    private array $requestedHostnames = [];

    public function test_the_preview_label_is_the_domain_with_dashes(): void
    {
        $this->assertSame('wp-com', PreviewTunnel::label('wp.com'));
        $this->assertSame('shop-example-co-uk', PreviewTunnel::label('Shop.Example.co.uk'));
        $this->assertSame(63, strlen(PreviewTunnel::label(str_repeat('a', 70) . '.com')));
    }

    public function test_a_new_project_is_created_on_the_kept_domain_and_previewed(): void
    {
        $migration = $this->start(MigrationTarget::newProject('wp.com', null));
        $creator = $this->creator();

        $this->createProject($creator, $this->preview())->run($migration);

        $migration->refresh();
        $this->assertSame([['wpcom', 'wp.com']], $creator->calls);
        $this->assertSame('wpcom', $migration->target_project);
        $this->assertSame('wp.com', $migration->target_label);
        $this->assertSame('wp-com.panelalpha.online', $migration->preview_hostname);

        $status = $migration->toStatusArray();
        $this->assertSame('wpcom', $status['target_project']);
        $this->assertSame('wp.com', $status['target_domain']);
        $this->assertTrue($status['created_project']);
        $this->assertSame('https://wp-com.panelalpha.online', $status['preview_url']);
        $this->assertSame(['type' => 'A', 'value' => self::ENGINE_IPV4], $status['dns']);
    }

    public function test_the_preview_keeps_the_source_subdirectory(): void
    {
        $migration = $this->start(MigrationTarget::newProject('wp.com', null), 'https://www.wp.com/blog');

        $this->createProject($this->creator(), $this->preview())->run($migration);

        $this->assertSame('https://wp-com.panelalpha.online/blog', $migration->fresh()->toStatusArray()['preview_url']);
    }

    public function test_a_taken_preview_label_gets_a_suffix(): void
    {
        $migration = $this->start(MigrationTarget::newProject('wp.com', null));

        $this->createProject($this->creator(), $this->preview(takenFirst: true))->run($migration);

        $this->assertMatchesRegularExpression('/^wp-com-[0-9a-f]{4}\.panelalpha\.online$/', (string) $migration->fresh()->preview_hostname);
        $this->assertSame('wp-com.panelalpha.online', $this->requestedHostnames[0]);
    }

    public function test_without_connect_there_is_no_preview_and_the_import_goes_on(): void
    {
        $this->withoutConnect();
        $migration = $this->start(MigrationTarget::newProject('wp.com', null));

        $this->createProject($this->creator(), $this->preview())->run($migration);

        $migration->refresh();
        $this->assertSame([], $this->requestedHostnames);
        $this->assertNull($migration->toStatusArray()['preview_url']);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_CREATE_PROJECT)['status']);
    }

    public function test_a_refused_preview_does_not_fail_the_step(): void
    {
        $migration = $this->start(MigrationTarget::newProject('wp.com', null));
        $preview = new PreviewTunnel(static fn (): Tunnel => throw new PanelAlphaException('Connect is down'));

        $this->createProject($this->creator(), $preview)->run($migration);

        $this->assertNull($migration->fresh()->preview_hostname);
    }

    public function test_an_existing_project_is_adopted_not_created(): void
    {
        $this->project('shop', 'shop.example.com');
        $migration = $this->start(MigrationTarget::existingProject('shop', 'shop.example.com'));
        $creator = $this->creator();

        $this->createProject($creator, $this->preview())->run($migration);

        $migration->refresh();
        $this->assertSame([], $creator->calls);
        $this->assertFalse($migration->toStatusArray()['created_project']);
        $this->assertSame('shop', $migration->target_project);
        $this->assertSame('shop-example-com.panelalpha.online', $migration->preview_hostname);
    }

    public function test_an_allocated_online_name_needs_no_preview_and_no_dns(): void
    {
        $migration = $this->start(MigrationTarget::newProject(null, null), 'http://198.51.100.20/');
        $creator = $this->creator('wordpress-a1b2.panelalpha.online');

        $this->createProject($creator, $this->preview())->run($migration);

        $status = $migration->fresh()->toStatusArray();
        $this->assertSame([['wordpress', null]], $creator->calls);
        $this->assertSame('wordpress-a1b2.panelalpha.online', $status['target_domain']);
        $this->assertNull($status['preview_url']);
        $this->assertNull($status['dns']);
    }

    private function start(MigrationTarget $target, string $sourceUrl = 'https://www.wp.com'): WordpressMigration
    {
        return WordpressMigration::start($sourceUrl, 'admin', 'secret', $target);
    }

    private function createProject(MigrationProjectCreator $creator, PreviewTunnel $preview): CreateProjectStep
    {
        return new CreateProjectStep($creator, $preview, static fn (string $name): bool => !User::existsByUsername($name));
    }

    private function creator(?string $allocated = null): MigrationProjectCreator
    {
        return new class ($this, $allocated) implements MigrationProjectCreator {
            /** @var list<array{0: string, 1: ?string}> */
            public array $calls = [];

            public function __construct(private WordpressMigrationPreviewTest $test, private ?string $allocated)
            {
            }

            public function create(string $username, ?string $domain = null): string
            {
                $this->calls[] = [$username, $domain];
                $name = $domain ?? $this->allocated ?? throw new RuntimeException('no domain');
                $this->test->makeProject($username, $name);

                return $name;
            }
        };
    }

    public function makeProject(string $username, string $domain): Domain
    {
        return $this->project($username, $domain);
    }

    private function preview(bool $takenFirst = false): PreviewTunnel
    {
        return new PreviewTunnel(function (User $user, Domain $domain, string $hostname) use ($takenFirst): Tunnel {
            $this->requestedHostnames[] = $hostname;
            if ($takenFirst && count($this->requestedHostnames) === 1) {
                throw new PanelAlphaException("Hostname '{$hostname}' is already in use.");
            }

            return Tunnel::create([
                'domain_id' => $domain->id,
                'user_id' => $user->id,
                'provider' => Tunnel::PROVIDER_PANELALPHA,
                'hostname' => $hostname,
                'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW],
            ]);
        });
    }
}
