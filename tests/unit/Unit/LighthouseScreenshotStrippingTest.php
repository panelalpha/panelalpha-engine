<?php

namespace Tests\Unit;

use App\Http\Controllers\LighthouseController;
use ReflectionMethod;
use Tests\TestCase;

class LighthouseScreenshotStrippingTest extends TestCase
{
    /** @param array<string, mixed> $result */
    private function strip(array $result): array
    {
        $controller = new LighthouseController();
        $method = new ReflectionMethod($controller, 'stripScreenshotData');
        $method->invokeArgs($controller, [&$result]);

        return $result;
    }

    public function test_the_embedded_screenshot_is_replaced_with_its_byte_count(): void
    {
        $result = $this->strip([
            'audits' => [
                'final-screenshot' => [
                    'score' => 1,
                    'details' => ['type' => 'screenshot', 'data' => self::jpeg(98000)],
                ],
            ],
        ]);

        $this->assertNull($result['audits']['final-screenshot']['details']['data']);
        $this->assertSame(98000, $result['audits']['final-screenshot']['details']['dataStrippedBytes']);
        $this->assertSame(1, $result['audits']['final-screenshot']['score']);
    }

    private static function jpeg(int $bytes): string
    {
        $prefix = 'data:image/jpeg;base64,';

        return $prefix . str_repeat('A', $bytes - strlen($prefix));
    }

    /**
     * What the tester's 117k-character MCP answer still carried: the filmstrip
     * and the full-page screenshot, which the old audits.*.details.data rule
     * never looked at.
     */
    public function test_every_embedded_image_in_the_report_is_stripped(): void
    {
        $result = $this->strip([
            'audits' => [
                'screenshot-thumbnails' => [
                    'details' => ['type' => 'filmstrip', 'items' => [
                        ['timing' => 300, 'data' => self::jpeg(9000)],
                        ['timing' => 600, 'data' => self::jpeg(9500)],
                    ]],
                ],
            ],
            'fullPageScreenshot' => [
                'screenshot' => ['data' => 'data:image/webp;base64,' . str_repeat('B', 400000), 'width' => 412],
                'nodes' => [],
            ],
        ]);

        $this->assertStringNotContainsString('data:', (string)json_encode($result));

        $items = $result['audits']['screenshot-thumbnails']['details']['items'];
        $this->assertSame(['timing' => 300, 'data' => null, 'dataStrippedBytes' => 9000], $items[0]);
        $this->assertSame(9500, $items[1]['dataStrippedBytes']);
        $this->assertSame(412, $result['fullPageScreenshot']['screenshot']['width']);
        $this->assertNull($result['fullPageScreenshot']['screenshot']['data']);
    }

    public function test_text_that_is_not_a_data_uri_is_kept(): void
    {
        $audit = ['details' => ['type' => 'debugdata', 'data' => 'metadata: fine']];

        $this->assertSame($audit, $this->strip(['audits' => ['x' => $audit]])['audits']['x']);
    }

    public function test_an_audit_with_no_embedded_data_is_untouched(): void
    {
        $result = $this->strip([
            'audits' => [
                'performance' => ['score' => 0.98, 'details' => ['type' => 'metric']],
            ],
        ]);

        $this->assertSame(['score' => 0.98, 'details' => ['type' => 'metric']], $result['audits']['performance']);
    }

    public function test_a_report_with_no_audits_key_does_not_error(): void
    {
        $this->assertSame([], $this->strip([]));
    }
}
