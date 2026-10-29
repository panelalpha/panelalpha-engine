<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use Tests\TestCase;

/**
 * Inspection copies a repository's own `.panelalpha/files/` into its clone as
 * the engine's user. A link the repository commits on that path made it write
 * wherever the engine can, core's own storage included.
 */
class RecipeFileOverlayLinksTest extends TestCase
{
    private string $base;

    private string $clone;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/pa-overlay-links-'.bin2hex(random_bytes(4));
        $this->clone = $this->base.'/clone';
        $this->outside = $this->base.'/core-storage';
        mkdir($this->clone.'/.panelalpha/files', 0755, true);
        mkdir($this->outside);
        file_put_contents($this->clone.'/.panelalpha/panelalpha.yaml', "description: x\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_a_file_is_not_written_through_a_linked_folder_out_of_the_clone(): void
    {
        $this->repositoryFile('pwn/evil.php', '<?php echo "written outside";');
        symlink($this->outside, $this->clone.'/pwn');

        $this->assertRefused('pwn');
        $this->assertSame([], $this->outsideEntries());
    }

    /**
     * The file-list inspection keeps a link only when it points inside the
     * repository on paper. Each of these does; the last one lands outside.
     */
    public function test_a_chain_of_links_that_each_stay_inside_on_paper_is_refused(): void
    {
        $this->assertNotNull(GitHubTree::resolveLink('here', '.'));
        $this->assertNotNull(GitHubTree::resolveLink('up', 'here/..'));
        $this->assertNotNull(GitHubTree::resolveLink('x', 'up/core-storage'));
        symlink('.', $this->clone.'/here');
        symlink('here/..', $this->clone.'/up');
        symlink('up/core-storage', $this->clone.'/x');
        $this->assertSame(realpath($this->outside), realpath($this->clone.'/x'));
        $this->repositoryFile('x/evil.php', '<?php echo "written outside";');

        $this->assertRefused('x');
        $this->assertSame([], $this->outsideEntries());
    }

    public function test_a_link_deeper_in_the_path_is_refused_before_anything_is_made_beyond_it(): void
    {
        mkdir($this->clone.'/app');
        symlink($this->outside, $this->clone.'/app/config');
        $this->repositoryFile('app/config/nested/settings.php', '<?php return [];');

        $this->assertRefused('app/config');
        $this->assertSame([], $this->outsideEntries());
    }

    public function test_a_link_at_the_file_itself_is_not_followed(): void
    {
        file_put_contents($this->outside.'/.env', "APP_KEY=original\n");
        symlink($this->outside.'/.env', $this->clone.'/.env');
        $this->repositoryFile('.env', "APP_KEY=from-the-repository\n");

        $this->assertRefused('.env');
        $this->assertSame("APP_KEY=original\n", file_get_contents($this->outside.'/.env'));
    }

    public function test_a_dangling_link_at_the_file_itself_does_not_create_its_target(): void
    {
        symlink($this->outside.'/index.php', $this->clone.'/index.php');
        $this->repositoryFile('index.php', '<?php echo "written outside";');

        $this->assertRefused('index.php');
        $this->assertSame([], $this->outsideEntries());
    }

    public function test_a_compose_file_is_not_written_through_a_link_the_repository_put_in_its_place(): void
    {
        mkdir($this->clone.'/.panelalpha/overrides');
        file_put_contents($this->clone.'/.panelalpha/overrides/docker-compose.yml', "services:\n  app:\n    image: nginx\n");
        symlink($this->outside.'/compose.yml', $this->clone.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);

        $this->assertRefused(EngineArtifacts::APP_CONFIG_COMPOSE);
        $this->assertSame([], $this->outsideEntries());
    }

    public function test_a_link_that_stays_inside_the_clone_is_refused_as_well(): void
    {
        mkdir($this->clone.'/public');
        symlink('public', $this->clone.'/web');
        $this->repositoryFile('web/robots.txt', "User-agent: *\n");

        $this->assertRefused('web');
        $this->assertFileDoesNotExist($this->clone.'/public/robots.txt');
    }

    public function test_files_land_in_real_folders_made_where_missing_and_replace_what_is_there(): void
    {
        file_put_contents($this->clone.'/index.php', '<?php echo "old";');
        $this->repositoryFile('index.php', '<?php echo "new";');
        $this->repositoryFile('config/nested/app.ini', "debug=0\n");

        $written = RecipeFileOverlay::apply($this->clone, null);

        sort($written);
        $this->assertSame(['config/nested/app.ini', 'index.php'], $written);
        $this->assertSame('<?php echo "new";', file_get_contents($this->clone.'/index.php'));
        $this->assertSame("debug=0\n", file_get_contents($this->clone.'/config/nested/app.ini'));
        $this->assertFalse(is_link($this->clone.'/index.php'));
        $this->assertTrue(is_dir($this->clone.'/config/nested') && ! is_link($this->clone.'/config'));
        $this->assertSame([], glob($this->clone.'/{,config/nested/}.panelalpha-overlay-*', GLOB_BRACE));
    }

    private function repositoryFile(string $relative, string $contents): void
    {
        $path = $this->clone.'/.panelalpha/files/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function assertRefused(string $link): void
    {
        try {
            RecipeFileOverlay::apply($this->clone, null);
            $this->fail('The overlay wrote through a link.');
        } catch (InspectException $e) {
            $this->assertStringContainsString("{$link} is a symbolic link in the repository", $e->getMessage());
        }
    }

    /** @return list<string> */
    private function outsideEntries(): array
    {
        return array_values(array_diff(scandir($this->outside), ['.', '..']));
    }
}
