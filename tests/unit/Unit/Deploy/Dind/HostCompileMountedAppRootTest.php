<?php

namespace Tests\Unit\Deploy\Dind;

use App\System\Project\Dind\HostCompile;
use PHPUnit\Framework\TestCase;

/**
 * A host-run project is mounted from its app_root, so it is built there too;
 * a subtree that resolves outside the checkout is refused, since the chown
 * after the compile runs on the host.
 */
class HostCompileMountedAppRootTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-mounted-root-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/project/web', 0777, true);
        mkdir($this->dir . '/outside');
        symlink($this->dir . '/outside', $this->dir . '/project/escape');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/project/escape');
        @rmdir($this->dir . '/project/web');
        @rmdir($this->dir . '/project');
        @rmdir($this->dir . '/outside');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function appDir(string $appRoot): string
    {
        return (string) (new \ReflectionMethod(HostCompile::class, 'mountedAppDir'))
            ->invoke(null, $this->dir . '/project', $appRoot);
    }

    public function test_no_app_root_builds_the_checkout(): void
    {
        $this->assertSame($this->dir . '/project', $this->appDir(''));
    }

    public function test_an_app_root_builds_in_the_subtree(): void
    {
        $this->assertSame($this->dir . '/project/web', $this->appDir('web'));
    }

    public function test_a_symlink_out_of_the_checkout_is_refused(): void
    {
        $this->expectExceptionMessage("app_root 'escape' is not a directory inside the project");
        $this->appDir('escape');
    }

    public function test_a_missing_subtree_is_refused(): void
    {
        $this->expectExceptionMessage('is not a directory inside the project');
        $this->appDir('nope');
    }
}
