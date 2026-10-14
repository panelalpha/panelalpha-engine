<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\BundlerSpaProbe;

/**
 * A `start` that is only a bundler dev server, beside a `build`, is a static
 * site; anything with a real server keeps running as Node.
 */
class BundlerSpaProbeTest extends ProbeTestCase
{
    /** @param array<string, mixed> $package */
    private function evaluate(array $package): bool
    {
        $this->writeJson('package.json', $package);

        return (new BundlerSpaProbe())->evaluate($this->context());
    }

    public function test_dev_server_only_starts_are_claimed(): void
    {
        foreach ([
            'webpack serve',
            'webpack serve --mode development --open',
            'webpack-dev-server --hot',
            'npx webpack serve',
            'vue-cli-service serve',
            'ng serve',
            'parcel index.html',
            'cross-env NODE_ENV=development webpack serve',
            'npm install && webpack serve',
        ] as $start) {
            $this->assertTrue(
                $this->evaluate(['scripts' => ['build' => 'webpack', 'start' => $start]]),
                $start
            );
        }
    }

    public function test_a_start_that_calls_a_dev_script_is_followed(): void
    {
        $this->assertTrue($this->evaluate([
            'scripts' => ['build' => 'webpack', 'dev' => 'webpack serve', 'start' => 'npm run dev'],
        ]));
    }

    public function test_a_start_that_runs_anything_else_is_not_claimed(): void
    {
        foreach (['node server.js', 'webpack serve && node server.js', 'parcel build index.html', 'webpack'] as $start) {
            $this->assertFalse(
                $this->evaluate(['scripts' => ['build' => 'webpack', 'start' => $start]]),
                $start
            );
        }
    }

    public function test_no_build_script_is_not_claimed(): void
    {
        $this->assertFalse($this->evaluate(['scripts' => ['start' => 'webpack serve']]));
    }

    public function test_a_server_dependency_or_server_js_is_not_claimed(): void
    {
        $scripts = ['build' => 'webpack', 'start' => 'webpack serve'];

        $this->assertFalse($this->evaluate(['scripts' => $scripts, 'dependencies' => ['fastify' => '^4']]));

        $this->write('server.js', "require('http').createServer().listen(3000);\n");
        $this->assertFalse($this->evaluate(['scripts' => $scripts]));
    }

    // ---- a build with no start script at all -----------------------------

    /**
     * github.com/kiwiirc/kiwiirc: `build` is `vue-cli-service build` and there
     * is no `start`.
     *
     * Requiring a start script to recognise a static SPA meant this repo was
     * never claimed here. It fell to Railpack, which built a Node image whose
     * package.json has no start script to run -- so the container
     * restart-looped and served nothing, instead of the built SPA on nginx.
     */
    public function test_a_build_with_no_start_script_is_a_static_spa(): void
    {
        $this->write('index.html', '<!doctype html><div id="app"></div>');

        $this->assertTrue($this->evaluate([
            'name' => 'kiwiirc',
            'scripts' => ['build' => 'vue-cli-service build --no-module', 'dev' => 'vue-cli-service serve'],
            'devDependencies' => ['@vue/cli-service' => '~5.0.8', 'webpack' => '^5.101.0'],
        ]));
    }

    /** The general shape, not the one repo: any bundler build, no start. */
    public function test_any_bundler_build_without_start_is_claimed(): void
    {
        $this->write('index.html', '<!doctype html>');

        foreach (['webpack', 'vite build', 'react-scripts build', 'parcel build index.html', 'ng build'] as $build) {
            $this->assertTrue($this->evaluate(['scripts' => ['build' => $build]]), $build);
        }
    }

    /** `public/index.html` is the other place a bundler puts its entry point. */
    public function test_an_entry_document_in_public_is_enough(): void
    {
        $this->write('public/index.html', '<!doctype html>');

        $this->assertTrue($this->evaluate(['scripts' => ['build' => 'vite build']]));
    }

    /**
     * github.com/georgemandis/bubo-rss: a command-line tool written in
     * TypeScript, with nothing to serve.
     *
     *     "build": "tsc",
     *     "bubo":  "node dist/index.js"
     *
     * No `start`, no framework -- and no `index.html` anywhere, because what it
     * builds is a script. It reaches this branch now that a missing `start` is
     * no longer a refusal, and the entry document is what tells the two apart:
     * claiming it produced `Static build finished but dist/index.html is
     * missing`, failing a deploy that Railpack runs.
     */
    public function test_a_typescript_cli_with_no_entry_document_is_not_claimed(): void
    {
        $this->assertFalse($this->evaluate([
            'name' => 'bubo-rss',
            'scripts' => ['build' => 'tsc', 'bubo' => 'node dist/index.js'],
            'dependencies' => ['commander' => '^12'],
        ]));
    }

    /** A build script and no entry document and no start: nothing to serve. */
    public function test_a_build_with_no_entry_document_and_no_start_is_not_claimed(): void
    {
        foreach (['tsc', 'tsc -p .', 'esbuild src/cli.ts --outfile=dist/cli.js'] as $build) {
            $this->assertFalse($this->evaluate(['scripts' => ['build' => $build]]), $build);
        }
    }

    /** A dev-server `start` is still the SPA case, entry document or not. */
    public function test_a_dev_server_start_needs_no_entry_document(): void
    {
        $this->assertTrue($this->evaluate(['scripts' => ['build' => 'webpack', 'start' => 'webpack serve']]));
    }

    /**
     * A blank or whitespace start script is still no start script.
     *
     * `"start": ""` is what a generator leaves behind, and it must not be read
     * as "has a start script that is not a dev server".
     */
    public function test_a_blank_start_script_is_treated_as_absent(): void
    {
        $this->write('index.html', '<!doctype html>');

        $this->assertTrue($this->evaluate(['scripts' => ['build' => 'webpack', 'start' => '']]));
        $this->assertTrue($this->evaluate(['scripts' => ['build' => 'webpack', 'start' => '   ']]));
    }

    /** A real server dependency still wins, start script or not. */
    public function test_a_server_dependency_still_declines_without_a_start_script(): void
    {
        foreach (['express', 'fastify', 'koa', 'next', 'nuxt', 'h3'] as $dep) {
            $this->assertFalse(
                $this->evaluate(['scripts' => ['build' => 'webpack'], 'dependencies' => [$dep => '^1']]),
                $dep
            );
        }
    }

    /** And so does a server.js at the root, which is a real entry point. */
    public function test_server_js_still_declines_without_a_start_script(): void
    {
        $this->write('server.js', "require('http').createServer().listen(3000);\n");

        $this->assertFalse($this->evaluate(['scripts' => ['build' => 'webpack']]));
    }

    /** Without a build there is nothing to serve, so it is still declined. */
    public function test_no_build_script_still_declines_without_a_start_script(): void
    {
        $this->assertFalse($this->evaluate(['scripts' => ['dev' => 'webpack serve']]));
    }

    public function test_output_dir_reads_the_webpack_config(): void
    {
        $cases = [
            "output: { path: path.resolve(__dirname, 'build') }" => 'build',
            'output: { path: path.join(__dirname, "public", "assets") }' => 'public/assets',
            "output: { filename: 'app.js', path: './www/' }" => 'www',
            "output: { path: __dirname + '/site' }" => 'site',
        ];
        foreach ($cases as $output => $expected) {
            $this->write('webpack.config.js', "module.exports = {\n  {$output},\n};\n");

            $this->assertSame($expected, BundlerSpaProbe::outputDir($this->dir), $output);
        }
    }

    public function test_output_dir_falls_back_when_it_cannot_be_read(): void
    {
        $this->assertSame('dist', BundlerSpaProbe::outputDir($this->dir));

        foreach ([
            // A path outside `output` is not the output path.
            "devServer: { static: { directory: path.resolve(__dirname, 'build') } }, output: { filename: 'a.js' }",
            'output: { path: outDir }',
            "output: { path: '/var/www/html' }",
            "output: { path: path.resolve(__dirname, '../elsewhere') }",
        ] as $body) {
            $this->write('webpack.config.js', "module.exports = { {$body} };\n");

            $this->assertSame('dist', BundlerSpaProbe::outputDir($this->dir), $body);
        }
    }

    public function test_output_dir_reads_an_esm_config(): void
    {
        $this->write('webpack.config.mjs', "export default { output: { path: path.resolve(import.meta.dirname, 'out') } };\n");

        $this->assertSame('out', BundlerSpaProbe::outputDir($this->dir));
    }
}
