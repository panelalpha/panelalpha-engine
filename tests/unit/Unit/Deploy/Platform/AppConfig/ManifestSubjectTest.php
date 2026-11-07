<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * A refused app config names which of the documents answering to
 * `panelalpha.yaml` it is, and which key made it describe a platform.
 */
class ManifestSubjectTest extends TestCase
{
    private const REFUSAL = "declares port, strategy but has no 'id', or 'extends' naming the recipe to start from";

    private const DESCRIBING = "port: 3000\nstrategy: static\n";

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/manifest-subject-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/project', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
    }

    public function test_a_file_in_the_repository_is_named_by_its_path(): void
    {
        $this->write('project/panelalpha.yaml', self::DESCRIBING);

        $this->assertSame(
            'panelalpha.yaml: '.self::REFUSAL,
            $this->refusal(fn () => $this->loadProject())
        );
    }

    public function test_a_file_in_the_panelalpha_directory_is_named_by_its_path(): void
    {
        $this->write('project/.panelalpha/panelalpha.yaml', self::DESCRIBING);

        $this->assertSame(
            '.panelalpha/panelalpha.yaml: '.self::REFUSAL,
            $this->refusal(fn () => $this->loadProject())
        );
    }

    /** The block a page carries is parsed by the YAML code, but the page is the file to open. */
    public function test_a_block_of_a_markdown_page_is_named_after_the_page(): void
    {
        $this->write(
            'project/panelalpha.md',
            "- `panelalpha.yaml`\n```yaml\n".self::DESCRIBING."```\n"
        );

        $this->assertSame(
            'panelalpha.md: '.self::REFUSAL,
            $this->refusal(fn () => $this->loadProject())
        );
    }

    /**
     * The project ships nothing, so only the slug says which repository the recipe is for.
     */
    public function test_an_engine_recipe_is_named_by_its_slug(): void
    {
        $this->write('sources/github.com/acme/app/panelalpha.yaml', self::DESCRIBING);

        $this->assertSame(
            'engine recipe github.com/acme/app/panelalpha.yaml: '.self::REFUSAL,
            $this->refusal(
                fn () => SourceRecipes::at($this->tmpDir.'/sources/github.com/acme/app', 'github.com/acme/app')
            )
        );
    }

    /**
     * A recipe is not mistaken for the project's own; only the engine's legacy host pages are `paemd`.
     */
    public function test_the_origin_is_named_in_the_words_a_deploy_log_uses(): void
    {
        $origins = array_map(
            AppConfigLocator::origin(...),
            AppConfigLocator::candidates($this->tmpDir.'/project', 'https://github.com/acme/app.git')
        );

        $this->assertSame(
            ['repository', 'repository', 'repository', 'engine', 'paemd', 'paemd'],
            $origins
        );
    }

    private function loadProject(): void
    {
        AppConfig::load(new LocalAppConfigSource, $this->tmpDir.'/project');
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    /** @param callable(): mixed $read */
    private function refusal(callable $read): string
    {
        try {
            $read();
        } catch (ManifestException $e) {
            return $e->getMessage();
        }

        $this->fail('the app config was accepted');
    }
}
