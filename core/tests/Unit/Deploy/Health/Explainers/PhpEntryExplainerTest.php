<?php

namespace Tests\Unit\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainers\PhpEntryExplainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

class PhpEntryExplainerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-entry-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function touch(string $relative): void
    {
        @mkdir(dirname($this->dir . '/' . $relative), 0777, true);
        file_put_contents($this->dir . '/' . $relative, '<?php');
    }

    /** @return array{detail: string, fix: string} */
    private function explain(): array
    {
        return (new PhpEntryExplainer())->explain(
            ProjectContext::at($this->dir),
            new ProbedResponse(403, '', 'http://127.0.0.1:8000/')
        );
    }

    /**
     * oPodSync: the application is in server/, which is not one of the usual
     * places, and the customer was told the project may be a library.
     */
    public function test_an_index_outside_the_usual_places_is_named_as_the_docroot(): void
    {
        $this->touch('server/index.php');
        $this->touch('README.md');

        $result = $this->explain();

        $this->assertSame('Set `docroot: server` in panelalpha.yaml and redeploy.', $result['fix']);
        $this->assertStringNotContainsString('no index.php', $result['detail']);
    }

    /** The shallowest one wins, and dependencies are never a front page. */
    public function test_the_shallowest_index_outside_dependencies_is_named(): void
    {
        $this->touch('vendor/acme/lib/index.php');
        $this->touch('node_modules/x/index.php');
        $this->touch('app/www/index.php');
        $this->touch('app/www/admin/index.php');

        $this->assertSame('Set `docroot: app/www` in panelalpha.yaml and redeploy.', $this->explain()['fix']);
    }

    public function test_a_tree_without_an_index_is_still_told_so(): void
    {
        $this->touch('vendor/acme/lib/index.php');
        $this->touch('src/Lib.php');

        $this->assertStringContainsString('no index.php anywhere', $this->explain()['detail']);
    }

    public function test_a_usual_place_still_wins(): void
    {
        $this->touch('public/index.php');
        $this->touch('server/index.php');

        $this->assertSame('Set `docroot: public` in panelalpha.yaml and redeploy.', $this->explain()['fix']);
    }
}
