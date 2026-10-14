<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\ResolvedSource;
use PHPUnit\Framework\TestCase;

/**
 * A checkout an inspection can read, and the temporary copy it may have to
 * clean up.
 *
 * Inspecting an application before deploying it means cloning it somewhere
 * first. That somewhere has to go afterwards - a "report on this repo" that
 * leaves a copy behind fills the host one inspection at a time - and it has
 * to go even when the inspection threw, which is why release() is a method
 * rather than a destructor.
 */
class ResolvedSourceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-source-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        ResolvedSource::removeTree($this->dir);
        parent::tearDown();
    }

    public function test_a_source_carries_what_it_came_from(): void
    {
        $source = new ResolvedSource('git', 'https://github.com/acme/shop.git', $this->dir, ['ref' => 'main']);

        $this->assertSame('git', $source->type);
        $this->assertSame('https://github.com/acme/shop.git', $source->reference);
        $this->assertSame($this->dir, $source->dir);
        $this->assertSame(['ref' => 'main'], $source->meta);
    }

    public function test_an_empty_checkout_is_not_worth_inspecting(): void
    {
        // A clone that produced nothing: reporting "no platform detected"
        // would be true and useless.
        $this->assertTrue((new ResolvedSource('git', 'x', $this->dir))->isEmpty());
    }

    public function test_a_checkout_with_anything_in_it_is_worth_inspecting(): void
    {
        file_put_contents($this->dir . '/README.md', '# shop');

        $this->assertFalse((new ResolvedSource('git', 'x', $this->dir))->isEmpty());
    }

    public function test_a_dotfile_alone_still_counts_as_content(): void
    {
        // A repo whose root is `.github/` and `.gitignore` is a real repo.
        mkdir($this->dir . '/.github');

        $this->assertFalse((new ResolvedSource('git', 'x', $this->dir))->isEmpty());
    }

    public function test_a_temporary_clone_is_removed_when_released(): void
    {
        $temp = $this->dir . '/clone';
        mkdir($temp . '/src', 0777, true);
        file_put_contents($temp . '/src/index.js', 'console.log(1)');

        (new ResolvedSource('git', 'x', $temp, [], $temp))->release();

        $this->assertDirectoryDoesNotExist($temp);
    }

    public function test_a_directory_the_engine_did_not_create_is_never_removed(): void
    {
        // Inspecting an account's existing checkout in place. Releasing that
        // would delete the customer's application.
        file_put_contents($this->dir . '/index.html', '<h1>hi</h1>');

        (new ResolvedSource('account', 'acme', $this->dir))->release();

        $this->assertDirectoryExists($this->dir);
        $this->assertFileExists($this->dir . '/index.html');
    }

    public function test_releasing_twice_is_harmless(): void
    {
        $temp = $this->dir . '/clone';
        mkdir($temp);
        $source = new ResolvedSource('git', 'x', $temp, [], $temp);

        $source->release();
        $source->release();

        $this->assertDirectoryDoesNotExist($temp);
    }

    public function test_a_nested_tree_is_removed_entirely(): void
    {
        $temp = $this->dir . '/clone';
        mkdir($temp . '/a/b/c', 0777, true);
        file_put_contents($temp . '/a/b/c/deep.txt', 'x');
        file_put_contents($temp . '/a/.hidden', 'x');

        ResolvedSource::removeTree($temp);

        $this->assertDirectoryDoesNotExist($temp);
    }

    public function test_a_symlink_is_unlinked_rather_than_followed(): void
    {
        // Removing a clone must not delete whatever a symlink in it points
        // at - node_modules trees are full of them.
        $outside = $this->dir . '/keep-me';
        mkdir($outside);
        file_put_contents($outside . '/important.txt', 'x');

        $temp = $this->dir . '/clone';
        mkdir($temp);
        symlink($outside, $temp . '/link');

        ResolvedSource::removeTree($temp);

        $this->assertDirectoryDoesNotExist($temp);
        $this->assertFileExists($outside . '/important.txt');
    }

    public function test_removing_something_that_is_not_there_is_harmless(): void
    {
        ResolvedSource::removeTree($this->dir . '/never-existed');
        ResolvedSource::removeTree('');

        $this->addToAssertionCount(1);
    }
}
