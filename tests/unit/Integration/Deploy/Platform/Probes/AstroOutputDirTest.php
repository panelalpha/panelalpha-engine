<?php

namespace Tests\Integration\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\AstroOutputDir;

/**
 * The directory nginx serves a static Astro build from. InBrowserApp builds its site in the apps/web workspace and
 * corosolto sets the Cloudflare adapter, which writes the pages to dist/client; both were served from an empty or
 * page-less `dist` and answered 403.
 */
class AstroOutputDirTest extends ProbeTestCase
{
    public function test_a_plain_site_builds_into_dist(): void
    {
        $this->site('', "export default defineConfig({});\n");

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Astro', $decision['label']);
        $this->assertSame('dist', $decision['output_directory']);
    }

    public function test_an_adapter_build_is_served_from_its_client_half(): void
    {
        $this->site('', "import cloudflare from '@astrojs/cloudflare';\nexport default defineConfig({\n  output: 'static',\n  adapter: cloudflare({ imageService: 'passthrough' }),\n});\n");

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Astro', $decision['label']);
        $this->assertSame('dist/client', $decision['output_directory']);
    }

    public function test_an_adapter_in_a_comment_is_not_set(): void
    {
        $this->site('', "/* adapter: vercel() was dropped */\nexport default defineConfig({\n  // adapter: node(),\n});\n");

        $this->assertSame('dist', AstroOutputDir::outputDir($this->dir));
    }

    public function test_a_literal_out_dir_is_read_and_vites_own_is_not(): void
    {
        $this->site('', "export default defineConfig({\n  outDir: './public-site/',\n  vite: { build: { outDir: 'ignored' } },\n});\n");
        $this->assertSame('public-site', AstroOutputDir::outputDir($this->dir));

        $this->site('', "export default defineConfig({\n  vite: { build: { outDir: 'ignored' } },\n});\n");
        $this->assertSame('dist', AstroOutputDir::outputDir($this->dir));
    }

    public function test_a_workspace_site_builds_into_its_own_dist(): void
    {
        $this->writeJson('package.json', [
            'private' => true,
            'scripts' => ['build' => 'pnpm tool-registry:generate && pnpm --filter web build'],
            'devDependencies' => ['astro' => 'catalog:'],
        ]);
        $this->write('pnpm-workspace.yaml', "packages:\n  - \"apps/*\"\n  - \"packages/*\"\n");
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->writeJson('apps/web/package.json', ['name' => 'web', 'scripts' => ['build' => 'astro build']]);
        $this->write('apps/web/astro.config.mjs', "export default defineConfig({ trailingSlash: 'always' });\n");
        $this->writeJson('packages/ui/package.json', ['name' => '@workspace/ui']);

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('Astro', $decision['label']);
        $this->assertSame('apps/web/dist', $decision['output_directory']);
    }

    public function test_of_several_workspace_sites_the_one_the_build_filters_to_is_served(): void
    {
        $this->workspaceWithTwoSites('pnpm --filter @acme/www build');
        $this->assertSame('apps/www/dist', AstroOutputDir::outputDir($this->dir));

        $this->workspaceWithTwoSites('npm run build --workspace=docs');
        $this->assertSame('apps/docs/dist', AstroOutputDir::outputDir($this->dir));
    }

    public function test_several_workspace_sites_without_a_filter_keep_the_root_dist(): void
    {
        $this->workspaceWithTwoSites('pnpm -r build');

        $this->assertSame('dist', AstroOutputDir::outputDir($this->dir));
    }

    private function site(string $dir, string $config): void
    {
        $prefix = $dir === '' ? '' : $dir.'/';
        $this->writeJson($prefix.'package.json', [
            'scripts' => ['build' => 'astro build'],
            'dependencies' => ['astro' => '^5.0.0', '@astrojs/cloudflare' => '^12.0.0'],
        ]);
        $this->write($prefix.'package-lock.json', '{"lockfileVersion":3,"packages":{}}');
        $this->write($prefix.'astro.config.mjs', $config);
    }

    private function workspaceWithTwoSites(string $build): void
    {
        $this->writeJson('package.json', [
            'private' => true,
            'workspaces' => ['apps/*'],
            'scripts' => ['build' => $build],
            'devDependencies' => ['astro' => '^5.0.0'],
        ]);
        foreach (['www' => '@acme/www', 'docs' => '@acme/docs'] as $dir => $name) {
            $this->writeJson("apps/{$dir}/package.json", ['name' => $name]);
            $this->write("apps/{$dir}/astro.config.mjs", "export default {};\n");
        }
    }
}
