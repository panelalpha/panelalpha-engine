<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Telemetry\DetectionSignal;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the "no recipe claimed this project" signal.
 *
 * Detection and the signal are exercised together wherever it is cheap to do
 * so: what matters is that a project the manifests do not claim raises the
 * event, and asserting that against a hand-written decision array would prove
 * only that the constant is spelled the same in two files.
 *
 * No Laravel dependencies — plain PHPUnit and a temp directory.
 */
class DetectionSignalTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/detection-signal-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_a_railpack_project_reports_that_no_recipe_matched(): void
    {
        // No platform manifest claims a bare package.json; Node recognises it,
        // so Railpack gets the project.
        $this->writeFile('package.json', '{"name":"app"}');
        $decision = DetectProjectStrategy::detect($this->tmpDir);
        $this->assertSame(Strategies::RAILPACK, $decision['strategy']);

        $event = DetectionSignal::forDecision($decision, $this->tmpDir);

        $this->assertNotNull($event);
        $this->assertSame(DetectionSignal::RAILPACK, $event['signal']);
        $this->assertStringContainsString('Railpack', $event['detail']);
    }

    public function test_the_railpack_detail_names_the_toolchain_that_recognised_the_project(): void
    {
        $this->writeFile('package.json', '{"name":"app"}');
        $decision = DetectProjectStrategy::detect($this->tmpDir);

        $event = DetectionSignal::forDecision($decision, $this->tmpDir);

        // Which recipe to write next is the whole point of the event.
        $this->assertNotNull($event);
        $this->assertStringContainsString('recognised by', $event['detail']);
        $this->assertStringContainsString('node', $event['detail']);
    }

    public function test_a_project_nothing_recognises_still_reports_the_fallback(): void
    {
        $this->writeFile('README.md', 'just words');
        $decision = DetectProjectStrategy::detect($this->tmpDir);
        $this->assertSame(Strategies::FALLBACK, $decision['strategy']);

        $event = DetectionSignal::forDecision($decision, $this->tmpDir);

        $this->assertNotNull($event);
        $this->assertSame(DetectionSignal::FALLBACK, $event['signal']);
        $this->assertStringContainsString('Unknown', $event['detail']);
    }

    public function test_a_project_a_recipe_claimed_raises_nothing(): void
    {
        $this->writeFile('package.json', '{"dependencies":{"next":"14.0.0"}}');
        $this->writeFile('next.config.js', "module.exports = {}\n");
        $decision = DetectProjectStrategy::detect($this->tmpDir);
        $this->assertSame(Strategies::NEXTJS, $decision['strategy']);

        $this->assertNull(DetectionSignal::forDecision($decision, $this->tmpDir));
    }

    public function test_a_repository_that_brought_its_own_compose_raises_nothing(): void
    {
        $this->writeFile('docker-compose.yml', "services: {}\n");
        $decision = DetectProjectStrategy::detect($this->tmpDir);

        $this->assertSame(Strategies::COMPOSE, $decision['strategy']);
        $this->assertNull(DetectionSignal::forDecision($decision, $this->tmpDir));
    }

    public function test_the_detail_survives_a_project_directory_that_is_gone(): void
    {
        // The account is rolled back moments after a failed deploy. Losing the
        // checkout may cost the detail line; it may not cost the event, and it
        // may certainly not throw into the deploy.
        $event = DetectionSignal::forDecision(
            ['strategy' => Strategies::RAILPACK, 'label' => 'Railpack'],
            $this->tmpDir . '/gone'
        );

        $this->assertNotNull($event);
        $this->assertSame(DetectionSignal::RAILPACK, $event['signal']);
        $this->assertStringNotContainsString('recognised by', $event['detail']);
    }

    public function test_a_decision_missing_its_fields_is_not_reported(): void
    {
        $this->assertNull(DetectionSignal::forDecision([], $this->tmpDir));
    }

    public function test_recognised_runtimes_are_listed_rather_than_counted(): void
    {
        $this->writeFile('package.json', '{"name":"app"}');
        $this->writeFile('requirements.txt', "flask\n");

        $recognised = RuntimeRegistry::recognisedBy(ProjectContext::at($this->tmpDir));

        $this->assertContains('node', $recognised);
        $this->assertContains('python', $recognised);
        $this->assertTrue(RuntimeRegistry::anyRecognises(ProjectContext::at($this->tmpDir)));
    }

    public function test_nothing_recognises_a_project_with_no_manifest(): void
    {
        $this->writeFile('README.md', 'just words');

        $this->assertSame([], RuntimeRegistry::recognisedBy(ProjectContext::at($this->tmpDir)));
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
