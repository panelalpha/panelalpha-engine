<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A build of the Memos checkout does not run: it embeds a placeholder in place
 * of the web UI and refuses to start without build metadata. The recipe runs
 * the published image instead, through a replacement compose file.
 */
class MemosSourceRecipeTest extends TestCase
{
    public function test_it_replaces_the_compose_file_with_the_pinned_published_image(): void
    {
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $this->directory(), true);

        $this->assertNotNull($config);
        // No manifest of its own: the compose file it writes is what detection picks.
        $this->assertNull($config->manifest());
        $this->assertSame(AppConfig::COMPOSE_REPLACE, $config->composeMode());

        $compose = Yaml::parse((string) $config->replacingCompose());
        $app = $compose['services']['app'];
        $this->assertMatchesRegularExpression('#^neosmemo/memos:\d+\.\d+\.\d+$#', $app['image']);
        $this->assertSame(['5230:5230'], $app['ports']);
        $this->assertContains('memos-data:/var/opt/memos', $app['volumes']);
        $this->assertArrayHasKey('memos-data', $compose['volumes']);
        $this->assertArrayNotHasKey('build', $app);
    }

    private function directory(): string
    {
        return SourceRecipes::defaultDirectory() . '/github.com/usememos/memos';
    }
}
