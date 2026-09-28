<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * The recipes that could deploy a project, and pinning one of them.
 *
 * Run against the manifests the engine actually ships rather than fixtures:
 * the whole value of the list is that it says what a deploy on this host would
 * do, and a list built from a temp directory of invented YAML would agree with
 * itself and with nothing else.
 */
class PlatformCandidatesTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/candidates-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * The first candidate is the one that deploys. Nothing else in the list
     * means anything if that is not true: a panel would offer a choice whose
     * default disagreed with what pressing deploy does.
     */
    public function test_the_first_candidate_is_the_recipe_detection_picks(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');
        $this->writeFile('public/index.php', '<?php');

        $decision = DetectProjectStrategy::detect($this->tmpDir);

        $this->assertSame($decision['platform'], $this->candidates()[0]['id']);
    }

    /**
     * A checkout that two manifests both claim lists both, in the order the
     * registry walks them — which is the order that decided the winner.
     */
    public function test_every_manifest_that_claims_the_project_is_listed_in_priority_order(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');
        $this->writeFile('Dockerfile', "FROM php:8.3\nEXPOSE 8000\nCMD [\"php\", \"-S\", \"0.0.0.0:8000\"]\n");

        $ids = array_column($this->candidates(), 'id');

        // Dockerfile outranks Laravel, and both outrank the fallback.
        $this->assertSame(['dockerfile', 'laravel', 'railpack'], $ids);
    }

    /**
     * Railpack is what a deploy falls back to, so it is a choice a caller can
     * make on purpose rather than only by being unrecognisable.
     */
    public function test_railpack_closes_the_list_when_a_toolchain_recognises_the_project(): void
    {
        $this->writeFile('go.mod', "module demo\n\ngo 1.22\n");
        $this->writeFile('main.go', 'package main');

        $candidates = $this->candidates();
        $last = end($candidates);

        $this->assertSame('railpack', $last['id']);
        $this->assertSame(PlatformCandidates::VIA_FALLBACK, $last['via']);
    }

    /**
     * A project nothing recognises has nothing to offer. The fallback that
     * serves a placeholder page is not a recipe anyone would choose.
     */
    public function test_a_project_no_toolchain_recognises_offers_no_fallback(): void
    {
        $this->writeFile('README.md', '# notes');

        $this->assertSame([], $this->candidates());
    }

    /** The list travels with the inspection, which is where a caller reads it. */
    public function test_the_inspection_reports_the_candidates(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');

        $application = AppInspector::inspect($this->tmpDir)['application'];

        $this->assertSame('laravel', $application['platform']);
        $this->assertContains('laravel', array_column($application['candidates'], 'id'));
    }

    /**
     * Pinning is not a hint. The named recipe describes the project whether or
     * not its own `detect` block would have claimed it — php.yaml declines a
     * checkout with an artisan file, and pinning it has to override that.
     */
    public function test_a_pinned_recipe_replaces_the_detected_one(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');
        $this->writeFile('public/index.php', '<?php');

        $detected = DetectProjectStrategy::detect($this->tmpDir);
        $pinned = DetectProjectStrategy::detect($this->tmpDir, null, 'php');

        $this->assertSame('laravel', $detected['platform']);
        $this->assertSame('php', $pinned['platform']);
    }

    /** The one recipe with no manifest behind it is still pinnable. */
    public function test_railpack_can_be_pinned(): void
    {
        $this->writeFile('package.json', '{"name":"demo","scripts":{"start":"node index.js"}}');
        $this->writeFile('index.js', 'console.log(1)');

        $decision = DetectProjectStrategy::detect($this->tmpDir, null, 'railpack');

        $this->assertSame('railpack', $decision['strategy']);
    }

    /**
     * A typo has to fail. Quietly deploying the detected recipe instead is the
     * failure this parameter exists to remove — the caller would have no way
     * to tell it had been ignored.
     */
    public function test_an_unknown_recipe_is_refused_rather_than_ignored(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("No recipe called 'lavarel'");

        DetectProjectStrategy::detect($this->tmpDir, null, 'lavarel');
    }

    /**
     * The inspection is the preview of the pinned deploy, so an id it cannot
     * resolve is a finding on the report rather than a 500 on the endpoint.
     */
    public function test_the_inspection_reports_an_unknown_recipe_as_a_finding(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');

        $application = AppInspector::inspect($this->tmpDir, null, null, 'lavarel')['application'];

        $this->assertFalse($application['deployable']);
        $this->assertStringContainsString("No recipe called 'lavarel'", (string) $application['issue']);
    }

    /**
     * Previewing a pin and deploying it have to agree, or the panel that
     * renders the preview is lying about what the button does.
     */
    public function test_the_inspection_previews_the_pinned_recipe(): void
    {
        $this->writeFile('composer.json', '{"require":{"laravel/framework":"^11"}}');
        $this->writeFile('artisan', '#!/usr/bin/env php');
        $this->writeFile('public/index.php', '<?php');

        $application = AppInspector::inspect($this->tmpDir, null, null, 'php')['application'];

        $this->assertSame('php', $application['platform']);
        $this->assertSame(DetectProjectStrategy::detect($this->tmpDir, null, 'php')['label'], $application['label']);
    }

    /**
     * A shipped recipe whose check_skip is covered by one of its own checks/
     * (Apaxy) loads here too, instead of failing the deploy as an unasked check.
     */
    public function test_a_source_recipe_that_skips_a_check_its_own_checks_cover_is_listed(): void
    {
        $this->writeFile('Dockerfile', "FROM httpd:2.4\nEXPOSE 8080\n");

        $candidates = PlatformCandidates::forContext(
            ProjectContext::at($this->tmpDir, 'https://github.com/oupala/apaxy')
        );

        $this->assertSame(PlatformCandidates::VIA_RECIPE, $candidates[0]['via']);
        $this->assertSame('dockerfile', $candidates[0]['id']);
    }

    /**
     * @return list<array<string, mixed>>
     * @throws ManifestException
     */
    private function candidates(): array
    {
        return PlatformCandidates::forContext(ProjectContext::at($this->tmpDir, null));
    }

    private function writeFile(string $relative, string $contents = ''): void
    {
        $path = $this->tmpDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
