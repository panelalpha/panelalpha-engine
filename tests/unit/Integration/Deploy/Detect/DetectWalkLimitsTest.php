<?php

namespace Tests\Integration\Deploy\Detect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Detect\PhpSources;
use PHPUnit\Framework\TestCase;

/** Tree walks stop on an unexpectedly large tree, and a Dockerfile that reads outside the checkout is not used. */
class DetectWalkLimitsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/detect-limits-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function test_php_in_a_tree_past_two_hundred_directories_is_not_looked_for(): void
    {
        $project = $this->dir.'/project';
        mkdir($project.'/lib/zz', 0777, true);
        file_put_contents($project.'/lib/zz/index.php', '<?php');
        $this->assertTrue(PhpSources::present($project));

        for ($i = 0; $i < 200; $i++) {
            mkdir(sprintf('%s/lib/d%03d', $project, $i));
        }

        $this->assertFalse(PhpSources::present($project));
    }

    public function test_a_site_past_three_hundred_directories_is_not_judged(): void
    {
        $project = $this->dir.'/project';
        file_put_contents($project.'/index.html', '<html><body>hi</body></html>');
        mkdir($project.'/assets');
        $this->assertSame('index.html', HtmlSite::entry($project));

        for ($i = 0; $i < 300; $i++) {
            mkdir(sprintf('%s/assets/d%03d', $project, $i));
        }

        $this->assertNull(HtmlSite::entry($project));
    }

    public function test_a_dockerfile_linked_out_of_the_checkout_is_not_used(): void
    {
        $project = $this->dir.'/project';
        file_put_contents($this->dir.'/outside', "FROM nginx:alpine\n");
        symlink($this->dir.'/outside', $project.'/Dockerfile');

        $found = AccountHomeRead::confinedTo($project, static fn (): ?string => DockerfileFinder::find($project, ['dockerfile' => true]));

        $this->assertNull($found);
    }

    public function test_a_dockerfile_inside_the_checkout_is_used(): void
    {
        $project = $this->dir.'/project';
        file_put_contents($project.'/Dockerfile', "FROM nginx:alpine\n");

        $found = AccountHomeRead::confinedTo($project, static fn (): ?string => DockerfileFinder::find($project, ['dockerfile' => true]));

        $this->assertSame('Dockerfile', $found);
    }

    private function remove(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($dir);
    }
}
