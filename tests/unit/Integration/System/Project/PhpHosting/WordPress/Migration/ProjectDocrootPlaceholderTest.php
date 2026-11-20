<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress\Migration;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\AccountImportedSiteRemover;
use App\System\Project\PhpHosting\WordPress\Migration\ProjectDocroot;
use Mockery;
use Tests\TestCase;

/**
 * An import lands only in a docroot that holds nothing but the engine's placeholder, and takes back only what it put there.
 */
class ProjectDocrootPlaceholderTest extends TestCase
{
    private const DOCROOT = '/home/alice/shop.example.test/public_html';

    /** @var array<string, list<string>> directory => names it holds */
    private array $tree = [];

    /** @var list<array{0: string, 1: bool}> */
    private array $removed = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_docroot_with_only_the_placeholder_takes_the_import(): void
    {
        $this->tree = [self::DOCROOT => ['index.html']];

        $this->assertTrue((new ProjectDocroot)->holdsOnlyPlaceholder($this->user(), $this->domain(), ''));
    }

    public function test_an_empty_docroot_takes_the_import(): void
    {
        $this->tree = [self::DOCROOT => []];

        $this->assertTrue((new ProjectDocroot)->holdsOnlyPlaceholder($this->user(), $this->domain(), ''));
    }

    public function test_a_docroot_with_other_files_does_not(): void
    {
        $this->tree = [self::DOCROOT => ['index.html', 'shop.php']];

        $this->assertFalse((new ProjectDocroot)->holdsOnlyPlaceholder($this->user(), $this->domain(), ''));
    }

    public function test_a_docroot_with_wordpress_core_does_not_even_for_an_empty_subdirectory(): void
    {
        $this->tree = [self::DOCROOT.'/wp-includes/version.php' => [], self::DOCROOT.'/blog' => []];

        $this->assertFalse((new ProjectDocroot)->holdsOnlyPlaceholder($this->user(), $this->domain(), 'blog'));
    }

    public function test_a_subdirectory_that_does_not_exist_yet_takes_the_import(): void
    {
        $this->tree = [self::DOCROOT => ['index.php', 'shop.php']];

        $this->assertTrue((new ProjectDocroot)->holdsOnlyPlaceholder($this->user(), $this->domain(), 'blog'));
    }

    public function test_the_imported_files_go_and_the_placeholder_stays(): void
    {
        $this->tree = [self::DOCROOT => ['index.html', 'wp-config.php', 'wp-content']];

        (new AccountImportedSiteRemover)->removeFiles($this->user(), self::DOCROOT.'/');

        $this->assertSame([
            [self::DOCROOT.'/wp-config.php', true],
            [self::DOCROOT.'/wp-content', true],
        ], $this->removed);
    }

    public function test_an_install_path_that_is_gone_has_nothing_to_remove(): void
    {
        (new AccountImportedSiteRemover)->removeFiles($this->user(), self::DOCROOT.'/blog');

        $this->assertSame([], $this->removed);
    }

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->domain = 'shop.example.test';
        $domain->setDetails(['document_root' => '/shop.example.test/public_html']);

        return $domain;
    }

    private function user(): User
    {
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists(rtrim($path, '/'), $this->tree));
        $files->shouldReceive('list')->andReturnUsing(fn (string $path): array => [
            'entries' => array_map(static fn (string $name): array => ['name' => $name], $this->tree[rtrim($path, '/')] ?? []),
        ]);
        $files->shouldReceive('remove')->andReturnUsing(function (string $path, bool $recursive = false): void {
            $this->removed[] = [$path, $recursive];
        });
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('fileManager')->andReturn($files);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice/');
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
