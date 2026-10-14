<?php

namespace Tests\Unit;

use App\Http\Controllers\LighthouseController;
use ReflectionMethod;
use Tests\TestCase;

class LighthouseSummaryTest extends TestCase
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function summary(array $result): array
    {
        $controller = new LighthouseController();

        return (new ReflectionMethod($controller, 'summary'))->invoke($controller, $result);
    }

    public function test_the_summary_keeps_the_scores_and_drops_the_evidence(): void
    {
        $rows = array_fill(0, 500, ['url' => 'https://example.com/' . str_repeat('x', 200), 'transferSize' => 1234]);
        $report = [
            'lighthouseVersion' => '12.8.0',
            'requestedUrl' => 'https://example.com/',
            'finalDisplayedUrl' => 'https://example.com/',
            'fetchTime' => '2026-09-30T10:00:00.000Z',
            'configSettings' => ['formFactor' => 'mobile', 'throttling' => ['rttMs' => 150]],
            'runWarnings' => [],
            'categories' => ['performance' => [
                'id' => 'performance', 'title' => 'Performance', 'score' => 0.91,
                'auditRefs' => [['id' => 'largest-contentful-paint', 'weight' => 25]],
            ]],
            'audits' => [
                'largest-contentful-paint' => [
                    'id' => 'largest-contentful-paint',
                    'title' => 'Largest Contentful Paint',
                    'description' => str_repeat('Explains LCP. ', 30),
                    'score' => 0.8,
                    'scoreDisplayMode' => 'numeric',
                    'numericValue' => 2512.3,
                    'numericUnit' => 'millisecond',
                    'displayValue' => '2.5 s',
                ],
                'network-requests' => [
                    'title' => 'Network Requests',
                    'score' => null,
                    'scoreDisplayMode' => 'informative',
                    'details' => ['type' => 'table', 'items' => $rows],
                ],
                'screenshot-thumbnails' => [
                    'title' => 'Screenshot Thumbnails',
                    'score' => null,
                    'scoreDisplayMode' => 'informative',
                    'details' => ['items' => [['data' => 'data:image/jpeg;base64,' . str_repeat('A', 20000)]]],
                ],
            ],
            'fullPageScreenshot' => ['screenshot' => ['data' => 'data:image/webp;base64,' . str_repeat('B', 200000)]],
            'i18n' => ['icuMessagePaths' => array_fill(0, 300, ['path' => 'audits.x.title'])],
            'timing' => ['entries' => array_fill(0, 300, ['name' => 'lh:audit', 'duration' => 1.0])],
        ];

        $summary = $this->summary($report);
        $json = (string)json_encode($summary);

        $this->assertLessThan(1500, strlen($json), $json);
        $this->assertStringNotContainsString('data:', $json);
        $this->assertSame(['title' => 'Performance', 'score' => 0.91], $summary['categories']['performance']);
        $this->assertSame([
            'title' => 'Largest Contentful Paint',
            'score' => 0.8,
            'scoreDisplayMode' => 'numeric',
            'numericValue' => 2512.3,
            'numericUnit' => 'millisecond',
            'displayValue' => '2.5 s',
        ], $summary['audits']['largest-contentful-paint']);
        $this->assertSame(['title' => 'Network Requests', 'scoreDisplayMode' => 'informative'], $summary['audits']['network-requests']);
        $this->assertSame('mobile', $summary['formFactor']);
        $this->assertSame('https://example.com/', $summary['finalDisplayedUrl']);
        $this->assertArrayNotHasKey('fullPageScreenshot', $summary);
    }

    public function test_a_failed_run_keeps_its_error(): void
    {
        $summary = $this->summary([
            'requestedUrl' => 'https://example.com/',
            'finalUrl' => 'https://example.com/',
            'runtimeError' => ['code' => 'NO_FCP', 'message' => 'The page did not paint any content.'],
            'categories' => ['performance' => ['title' => 'Performance', 'score' => null]],
            'audits' => ['first-contentful-paint' => [
                'title' => 'First Contentful Paint',
                'score' => null,
                'scoreDisplayMode' => 'error',
                'errorMessage' => 'The page did not paint any content.',
            ]],
        ]);

        $this->assertSame('NO_FCP', $summary['runtimeError']['code']);
        $this->assertSame('https://example.com/', $summary['finalDisplayedUrl']);
        $this->assertSame('The page did not paint any content.', $summary['audits']['first-contentful-paint']['errorMessage']);
        $this->assertSame(['title' => 'Performance', 'score' => null], $summary['categories']['performance']);
    }
}
