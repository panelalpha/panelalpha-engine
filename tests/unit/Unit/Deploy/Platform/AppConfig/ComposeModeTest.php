<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\System\Project\Dind\Paths;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Replacing the project's compose file versus layering over it.
 *
 * Compose reads `docker-compose.override.yml` on top of the base file only
 * when it is discovering files itself. Naming one with `-f` — which the
 * engine does — turns that off, so an override has to be listed explicitly or
 * it is copied into the project and silently ignored.
 */
class ComposeModeTest extends TestCase
{
    public function test_the_override_filename_is_the_one_compose_reserves(): void
    {
        $this->assertSame('docker-compose.override.yml', Paths::CLIENT_OVERRIDE_FILENAME);
    }

    public function test_replace_is_the_default_so_existing_pages_keep_their_behaviour(): void
    {
        $markdown = AppConfig::fromMarkdown(
            "- `docker-compose.yml`\n```yaml\nservices: {}\n```\n"
        );

        $this->assertSame(AppConfig::COMPOSE_REPLACE, $markdown?->composeMode());
    }

    public function test_yaml_can_ask_to_layer_instead(): void
    {
        $appConfig = AppConfig::fromYaml(
            "compose:\n  mode: override\n  content: |\n    services:\n      app:\n        volumes: ['./docker:/app/docker']\n"
        );

        $this->assertSame(AppConfig::COMPOSE_OVERRIDE, $appConfig?->composeMode());
        $this->assertStringContainsString('./docker:/app/docker', (string) $appConfig?->compose());
    }

    /**
     * The two modes must stay distinguishable: writing an override to the base
     * path would replace the file it was meant to extend.
     */
    public function test_the_modes_are_distinct_values(): void
    {
        $this->assertNotSame(AppConfig::COMPOSE_REPLACE, AppConfig::COMPOSE_OVERRIDE);
    }

    /**
     * Regression guard for the shape of the compose invocation: an override,
     * when present, must be passed as a second `-f`.
     *
     * Read through reflection rather than by opening a path, so moving the
     * builder to another class breaks this on its merits — a missing method —
     * instead of on a filename.
     */
    public function test_the_command_builder_lists_every_compose_file(): void
    {
        $this->assertStringContainsString(
            'foreach ($this->composeFiles() as $file)',
            $this->sourceOf(Paths::class, 'composeCommand'),
            'the compose command must list every file, not just the base one'
        );
    }

    /**
     * @param class-string $class
     */
    private function sourceOf(string $class, string $method): string
    {
        $reflected = new \ReflectionMethod($class, $method);
        $file = (string) $reflected->getFileName();
        $this->assertFileIsReadable($file);

        $lines = (array) file($file);
        $start = (int) $reflected->getStartLine() - 1;

        return implode('', array_slice($lines, $start, (int) $reflected->getEndLine() - $start));
    }
}
