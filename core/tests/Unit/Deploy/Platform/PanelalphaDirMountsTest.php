<?php

namespace Tests\Unit\Deploy\Platform;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * ~/.panelalpha is 0700 and holds the engine's own files (app credentials,
 * tunnel tokens). A recipe binds only what its service needs below it.
 */
class PanelalphaDirMountsTest extends TestCase
{
    public function test_no_shipped_recipe_binds_the_whole_panelalpha_directory(): void
    {
        $whole = [];
        foreach ($this->composeFiles() as $file) {
            foreach ($this->bindSources($file) as $source) {
                if (preg_match('#^(\.\./|~/|\$\{?HOME\}?/|/home/[^/]+/)\.panelalpha/?$#', $source) === 1) {
                    $whole[] = substr($file, strlen($this->root()) + 1) . ": {$source}";
                }
            }
        }

        $this->assertSame([], $whole);
    }

    /**
     * The five recipes that used to bind it all now bind their own subdirectory,
     * which their prepare hook creates as the account before compose runs.
     */
    public function test_each_former_whole_directory_recipe_binds_a_subdirectory_its_hook_creates(): void
    {
        foreach ([
            'gitlab.com/lazylibrarian/lazylibrarian' => 'lazylibrarian',
            'git.spip.net/spip/spip' => 'spip',
            'github.com/galette/galette' => 'galette',
            'github.com/dotclear/dotclear' => 'dotclear',
            'github.com/l2dy-sonarcloud/pmwiki' => 'pmwiki',
        ] as $recipe => $sub) {
            $dir = $this->root() . '/' . $recipe;
            $sources = $this->bindSources($dir . '/overrides/docker-compose.override.yml');
            $this->assertContains("../.panelalpha/{$sub}", $sources, $recipe);

            $hook = (string) @file_get_contents($dir . '/hooks/prepare.sh');
            $this->assertMatchesRegularExpression(
                '#HOME\}/\.panelalpha/' . $sub . '"\s*$.*?^mkdir -p|^mkdir -p "\$\{HOME\}/\.panelalpha/' . $sub . '"#ms',
                $hook,
                "{$recipe}: hooks/prepare.sh must create ~/.panelalpha/{$sub}"
            );
        }
    }

    /** @return list<string> */
    private function bindSources(string $file): array
    {
        $compose = Yaml::parse((string) file_get_contents($file), Yaml::PARSE_CUSTOM_TAGS);
        $sources = [];
        foreach ($this->untag($compose['services'] ?? null) as $service) {
            foreach ($this->untag($this->untag($service)['volumes'] ?? null) as $volume) {
                $volume = $volume instanceof TaggedValue ? $volume->getValue() : $volume;
                $source = is_array($volume) ? ($volume['source'] ?? null) : explode(':', (string) $volume, 2)[0];
                if (is_string($source) && str_contains($source, '.panelalpha')) {
                    $sources[] = rtrim($source, '/') === $source ? $source : rtrim($source, '/') . '/';
                }
            }
        }

        return $sources;
    }

    /** A `!override` / `!reset` list or map, read as the plain value. */
    private function untag(mixed $value): array
    {
        $value = $value instanceof TaggedValue ? $value->getValue() : $value;

        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function composeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (preg_match('#/overrides/[^/]*compose[^/]*\.ya?ml$#', $path) === 1) {
                $files[] = $path;
            }
        }
        sort($files);
        $this->assertNotEmpty($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4) . '/resources/sources';
    }
}
