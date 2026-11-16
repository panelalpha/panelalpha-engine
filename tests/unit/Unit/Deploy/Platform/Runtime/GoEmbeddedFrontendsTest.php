<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\GoEmbeddedFrontends;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_missing_project_directory_has_no_frontends(): void
    {
        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir . '/absent'));
        $this->assertSame([], GoEmbeddedFrontends::plan($this->dir . '/absent'));
    }

    public function test_go_files_without_an_embed_directive_are_skipped(): void
    {
        $this->write('main.go', "package main\n\nfunc main() {}\n");
        $this->write('ui/ui.go', "package ui\n\n// go:embed dist is prose, not a directive\n");
        $this->frontend('ui');

        $this->assertSame([], GoEmbeddedFrontends::missing($this->dir));
    }

    public function test_quoted_patterns_and_the_first_embed_per_frontend_win(): void
    {
        $this->write('web/b.go', "package web\n\n//go:embed \"dist\" `static`\nvar FS embed.FS\n");
        $this->write('web/a.go', "package web\n\n//go:embed all:build\nvar Other embed.FS\n");
        $this->write('.hidden/x.go', "package x\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->write('_examples/x.go', "package x\n\n//go:embed dist\nvar FS embed.FS\n");
        $this->write('tool/t.go', "package tool\n\n//go:embed ../web/dist *.tmpl\nvar FS embed.FS\n");
        $this->frontend('web');
        $this->frontend('.hidden');
        $this->frontend('_examples');
        $this->frontend('tool');

        $this->assertSame(
            [['dir' => 'web', 'embed' => 'web/a.go: build']],
            GoEmbeddedFrontends::missing($this->dir)
        );
    }

    public function test_the_scan_reads_at_most_three_thousand_go_files(): void
    {
        for ($i = 0; $i < 3005; $i++) {
            $this->write(sprintf('pkg%02d/f%04d.go', $i % 50, $i), '');
        }
        $goFiles = new \ReflectionMethod(GoEmbeddedFrontends::class, 'goFiles');

        $files = $goFiles->invoke(null, $this->dir);

        $this->assertCount(3000, $files);
        $sorted = $files;
        sort($sorted);
        $this->assertSame($sorted, $files);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function embeddingsPastTheCap(): array
    {
        return [
            'a root file sorting after the fillers' => ['zz.go', ['web']],
            'a file beside the fillers sorting before them' => ['pkg/main.go', ['pkg/web']],
            'a file beside the fillers sorting after them' => ['pkg/zz.go', []],
            'a deeper file sorting before the fillers' => ['pkg/a/main.go', []],
        ];
    }

    /**
     * Past the cap the shallowest files are read, then the first by name, whatever order the filesystem lists
     * them in.
     *
     * @param list<string> $expected
     */
    #[DataProvider('embeddingsPastTheCap')]
    public function test_the_cap_keeps_the_shallowest_files_whatever_order_they_were_written_in(string $embedding, array $expected): void
    {
        foreach (['first', 'last'] as $written) {
            $root = $this->treePastTheCap($embedding, $written);

            $this->assertSame($expected, array_column(GoEmbeddedFrontends::missing($root), 'dir'), "written {$written}");
        }
    }

    public function test_reaching_the_cap_is_told_and_staying_under_it_is_not(): void
    {
        $notes = [];
        $note = static function (string $line) use (&$notes): void {
            $notes[] = $line;
        };

        GoEmbeddedFrontends::plan($this->treePastTheCap('zz.go', 'first'), $note);
        $this->write('small/main.go', "package main\n");
        GoEmbeddedFrontends::plan($this->dir . '/small', $note);

        $this->assertSame(
            ['Looked for embedded frontends in 3000 of 3001 Go files, the least nested first; '
                . 'a frontend embedded by one of the others is not built before the Go build.'],
            $notes
        );
    }

    /** As many `pkg/z*.go` fillers as the cap allows, written before or after the embedding file. */
    private function treePastTheCap(string $embedding, string $written): string
    {
        $root = 'cap-' . str_replace('/', '-', $embedding) . '-' . $written;
        $cap = (new \ReflectionClassConstant(GoEmbeddedFrontends::class, 'MAX_GO_FILES'))->getValue();
        $embed = "package main\n\nimport \"embed\"\n\n//go:embed web/dist\nvar ui embed.FS\n";
        if ($written === 'first') {
            $this->write($root . '/' . $embedding, $embed);
        }
        for ($i = 0; $i < $cap; $i++) {
            $this->write(sprintf('%s/pkg/z%04d.go', $root, $i), "package pkg\n");
        }
        if ($written === 'last') {
            $this->write($root . '/' . $embedding, $embed);
        }
        $this->frontend($root . '/' . (dirname($embedding) === '.' ? '' : dirname($embedding) . '/') . 'web');

        return $this->dir . '/' . $root;
    }
}
