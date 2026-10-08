<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\GoEmbeddedFrontends;
use PHPUnit\Framework\TestCase;

/**
 * A Go server embedding a web UI the checkout does not contain stopped on
 * `pattern dist: no matching files found`. The layouts are the real ones.
 */
class GoEmbeddedFrontendsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/go-embed-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    private function frontend(string $dir, string $build = 'vite build'): void
    {
        $this->write(($dir === '' ? '' : $dir . '/') . 'package.json', (string) json_encode(['scripts' => ['build' => $build]]));
    }

    /** zincsearch: embed.go at the root embeds frontend/dist, built by frontend/package.json. */
    public function test_an_embedded_dist_is_built_by_the_package_json_beside_it(): void
    {
        $this->write('embed.go', "package zincsearch\n\nimport \"embed\"\n\n//go:embed frontend/dist\nvar Ui embed.FS\n");
        $this->frontend('frontend', 'tsc --noEmit && vite build');
        $this->write('frontend/package-lock.json', '{}');

        $plan = GoEmbeddedFrontends::plan($this->dir);

        $this->assertCount(1, $plan);
        $this->assertSame('frontend', $plan[0]['dir']);
        $this->assertSame('embed.go: frontend/dist', $plan[0]['embed']);
        $this->assertStringContainsString('npm ci', $plan[0]['install']);
        $this->assertSame('npm run build', $plan[0]['build']);
    }

    /** Dropserver and nginx-ui: the embedding package is the frontend's own directory. */
    public function test_an_embed_in_the_frontend_directory_itself(): void
    {
        $this->write('frontend-ds-host/embed.go', "package frontend\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->frontend('frontend-ds-host');
        $this->write('frontend-ds-host/yarn.lock', '');
        $this->write('app/app.go', "package app\n\n//go:embed all:dist i18n.json src/language\nvar DistFS embed.FS\n");
        $this->write('app/i18n.json', '{}');
        $this->write('app/src/language/en.po', '');
        $this->frontend('app');

        $missing = GoEmbeddedFrontends::missing($this->dir);

        $this->assertSame(['app', 'frontend-ds-host'], array_column($missing, 'dir'));
        $this->assertSame('app/app.go: dist', $missing[0]['embed']);
        $this->assertSame('yarn build', GoEmbeddedFrontends::plan($this->dir)[1]['build']);
    }

    public function test_a_present_embed_needs_nothing(): void
    {
        $this->write('main.go', "package main\n\n//go:embed static templates/*.html\nvar assets embed.FS\n");
        $this->write('static/app.css', '');
        $this->write('templates/index.html', '');
        $this->frontend('');

        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir));
    }

    /**
     * pomerium's downloaded Envoy binary, komari's theme tarball, seanime's
     * root `web` built from elsewhere: nothing beside them builds them.
     */
    public function test_a_missing_embed_with_no_frontend_beside_it_is_left_alone(): void
    {
        $this->write('main.go', "package main\n\n//go:embed all:web\nvar WebFS embed.FS\n");
        $this->write('seanime-web/package.json', (string) json_encode(['scripts' => ['build' => 'next build']]));
        $this->write('pkg/envoy/files/files.go', "package files\n\n//go:embed envoy-linux-amd64\nvar raw []byte\n");

        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir));
    }

    public function test_a_package_json_without_a_build_script_does_not_count(): void
    {
        $this->write('ui/embed.go', "package ui\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->write('ui/package.json', (string) json_encode(['scripts' => ['dev' => 'vite']]));

        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir));
    }

    public function test_tests_vendor_and_node_modules_are_not_read(): void
    {
        $this->write('ui/embed_test.go', "package ui\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->write('vendor/x/ui/embed.go', "package ui\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->write('ui/node_modules/y/embed.go', "package y\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->frontend('ui');
        $this->frontend('vendor/x/ui');

        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir));
    }
}
