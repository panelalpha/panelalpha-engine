<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use Tests\TestCase;

/**
 * The deploy lays a recipe's files over the checkout before
 * detection; inspect read the bare clone and called ESMira undeployable.
 */
class RecipeFileOverlayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-overlay-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_recipe_supplies_the_files_the_repository_lacks(): void
    {
        $written = RecipeFileOverlay::apply($this->dir, 'https://github.com/KL-Psychological-Methodology/ESMira');

        $this->assertContains('package.json', $written);
        $this->assertFileExists($this->dir . '/package.json');
    }

    public function test_a_repository_with_no_recipe_is_left_as_it_is(): void
    {
        file_put_contents($this->dir . '/index.php', '<?php');

        $this->assertSame([], RecipeFileOverlay::apply($this->dir, 'https://github.com/nobody/no-recipe-here'));
        $this->assertSame(['index.php'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    /**
     * The WordPress recipe ships its own compose file and the repository none.
     * The deploy writes it before detecting and runs compose; inspect has to
     * see the same file or it reports the `wordpress` php manifest instead.
     */
    public function test_a_recipe_compose_file_is_what_inspect_detects_as_the_deploy_does(): void
    {
        $url = 'https://github.com/WordPress/WordPress';
        $this->writeWordPressTree();
        $this->assertSame('php', DetectProjectStrategy::detect($this->dir, $url)['strategy']);

        $written = RecipeFileOverlay::apply($this->dir, $url);

        $this->assertContains(EngineArtifacts::APP_CONFIG_COMPOSE, $written);
        $decision = DetectProjectStrategy::detect($this->dir, $url);
        $this->assertSame('compose', $decision['strategy']);
        $this->assertSame($this->dir . '/' . EngineArtifacts::APP_CONFIG_COMPOSE, $decision['compose_path']);

        $report = AppInspector::inspect($this->dir, $url);
        $this->assertSame('compose', $report['application']['strategy']);
        $this->assertContains('mysql', array_column($report['services'], 'engine'));
    }

    public function test_an_override_mode_compose_is_not_written_into_the_checkout(): void
    {
        mkdir($this->dir . '/.panelalpha/overrides', 0755, true);
        file_put_contents($this->dir . '/.panelalpha/panelalpha.yaml', "description: x\n");
        file_put_contents(
            $this->dir . '/.panelalpha/overrides/docker-compose.override.yml',
            "services:\n  app:\n    environment:\n      A: b\n"
        );

        $this->assertNotContains(EngineArtifacts::APP_CONFIG_COMPOSE, RecipeFileOverlay::apply($this->dir, null));
        $this->assertFileDoesNotExist($this->dir . '/' . EngineArtifacts::APP_CONFIG_COMPOSE);
    }

    /** The four files the `wordpress` manifest's detect block asks for. */
    private function writeWordPressTree(): void
    {
        mkdir($this->dir . '/wp-includes');
        mkdir($this->dir . '/wp-admin');
        foreach (['index.php', 'wp-settings.php', 'wp-login.php', 'wp-includes/version.php', 'wp-admin/index.php'] as $file) {
            file_put_contents($this->dir . '/' . $file, '<?php');
        }
    }
}
