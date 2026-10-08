<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\AppRoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where /app comes from.
 *
 * Two callers have to agree about this and the cost of disagreement is
 * silent: FrameworkService mounts the subtree, EntrypointWriter writes the
 * stage script the base image looks for at /app/panelalpha-entrypoint.sh.
 * While the mount used `src` and the writer used the checkout root, the shim
 * found no script, logged "serving directly" to container stderr, and dropped
 * every install, upgrade and start command the manifest declared -- WordPress
 * answered 500 because its wp-config step never ran.
 */
class AppRootTest extends TestCase
{
    public function test_a_manifest_without_an_app_root_uses_the_checkout(): void
    {
        $this->assertSame('', AppRoot::relative([]));
        $this->assertSame('./', AppRoot::mount([]));
        $this->assertSame('/home/p/project', AppRoot::path('/home/p/project', []));
    }

    public function test_a_declared_subtree_is_used_by_both_callers(): void
    {
        $decision = ['app_root' => 'src'];

        $this->assertSame('src', AppRoot::relative($decision));
        $this->assertSame('./src', AppRoot::mount($decision));
        $this->assertSame('/home/p/project/src', AppRoot::path('/home/p/project', $decision));
    }

    /**
     * The mount source and the script destination must name the same
     * directory, whatever the value is. This is the property that broke.
     */
    #[DataProvider('roots')]
    public function test_the_mount_and_the_script_always_agree(mixed $declared): void
    {
        $decision = ['app_root' => $declared];
        $mount = AppRoot::mount($decision);
        $path = AppRoot::path('/home/p/project', $decision);

        // './src' under /home/p/project is /home/p/project/src.
        $expected = rtrim('/home/p/project/' . ltrim(substr($mount, 2), '/'), '/');

        $this->assertSame($expected, $path);
    }

    public static function roots(): array
    {
        return [
            'none' => [null],
            'empty' => [''],
            'simple' => ['src'],
            'nested' => ['apps/web'],
            'trailing slash' => ['src/'],
            'absolute, refused' => ['/etc'],
            'traversal, refused' => ['../../etc'],
            'traversal inside, refused' => ['src/../../etc'],
            'not a path, refused' => ['src;rm -rf /'],
        ];
    }

    /**
     * An absolute path is refused rather than reinterpreted: trimming the
     * leading slash off `/etc` would turn a value the schema rejects into a
     * relative path that mounts something, quietly.
     */
    #[DataProvider('refused')]
    public function test_a_value_that_could_climb_out_falls_back_to_the_checkout(string $declared): void
    {
        $this->assertSame('', AppRoot::relative(['app_root' => $declared]));
        $this->assertSame('./', AppRoot::mount(['app_root' => $declared]));
    }

    public static function refused(): array
    {
        return [
            ['/etc'],
            ['/'],
            ['../secrets'],
            ['src/../../etc'],
            ['a/../../b'],
            ['src;rm -rf /'],
            ['src $(id)'],
            ['sr c'],
        ];
    }

    public function test_a_trailing_slash_does_not_double_up_in_the_path(): void
    {
        $this->assertSame(
            '/home/p/project/src',
            AppRoot::path('/home/p/project/', ['app_root' => 'src/'])
        );
    }
}
