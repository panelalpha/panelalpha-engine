<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\AppConfigSource;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\AppConfig\PaemdPage;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * How a log line names where an app config came from: the file in the project, the slug the engine filed it under, or the path.
 */
class AppConfigLocatorDescribeTest extends TestCase
{
    public function test_a_repository_candidate_is_named_by_its_file(): void
    {
        $this->assertSame('panelalpha.yaml', AppConfigLocator::describe(['path' => '/home/a/project/panelalpha.yaml', 'origin' => AppConfigLocator::ORIGIN_REPOSITORY]));
    }

    public function test_an_engine_recipe_is_named_by_its_slug(): void
    {
        $this->assertSame('github.com/acme/app', AppConfigLocator::describe([
            'path' => SourceRecipes::defaultDirectory().'/github.com/acme/app',
            'origin' => AppConfigLocator::ORIGIN_ENGINE,
        ]));
    }

    public function test_a_legacy_page_is_named_by_its_directory_not_its_file(): void
    {
        $this->assertSame('github.com/acme/app', AppConfigLocator::describe([
            'path' => PaemdPage::pagesDirectory().'/github.com/acme/app/panelalpha.md',
            'origin' => AppConfigLocator::ORIGIN_ENGINE,
        ]));
        $this->assertSame('github.com/acme/app', AppConfigLocator::describe([
            'path' => PaemdPage::pagesDirectory().'/github.com/acme/app/panelalpha.yaml',
            'origin' => AppConfigLocator::ORIGIN_ENGINE,
        ]));
    }

    public function test_an_engine_path_outside_both_trees_is_named_in_full(): void
    {
        $this->assertSame('/srv/elsewhere/app', AppConfigLocator::describe(['path' => '/srv/elsewhere/app', 'origin' => AppConfigLocator::ORIGIN_ENGINE]));
    }

    public function test_a_legacy_page_is_its_own_origin(): void
    {
        $this->assertSame(AppConfigLocator::ORIGIN_PAEMD, AppConfigLocator::origin([
            'path' => PaemdPage::pagesDirectory().'/github.com/acme/app/panelalpha.md',
            'origin' => AppConfigLocator::ORIGIN_ENGINE,
        ]));
        $this->assertSame(AppConfigLocator::ORIGIN_ENGINE, AppConfigLocator::origin([
            'path' => SourceRecipes::defaultDirectory().'/github.com/acme/app',
            'origin' => AppConfigLocator::ORIGIN_ENGINE,
        ]));
    }

    public function test_a_malformed_legacy_page_is_named_as_an_engine_page_in_its_error(): void
    {
        $page = PaemdPage::pagesDirectory().'/github.com/acme/app/panelalpha.yaml';
        $source = new class($page) implements AppConfigSource
        {
            public function __construct(private readonly string $page) {}

            public function exists(string $path): bool
            {
                return $path === $this->page;
            }

            public function read(string $path): ?string
            {
                return $path === $this->page ? "env: [unterminated\n" : null;
            }

            public function isDirectory(string $path): bool
            {
                return false;
            }

            public function listFiles(string $dir): array
            {
                return [];
            }
        };

        try {
            AppConfigLocator::find($source, '/nonexistent/project', 'https://github.com/acme/app');
            $this->fail('a malformed page should be refused');
        } catch (ManifestException $e) {
            $this->assertStringContainsString('engine page github.com/acme/app/panelalpha.yaml', $e->getMessage());
        }
    }
}
