<?php

namespace Tests\Integration\Mcp;

use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\ApiTool;
use App\Mcp\Tools\MetricsLatestTool;
use App\Mcp\Tools\ProjectListSummaryTool;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ToolPolicyEdgesTest extends TestCase
{
    public function test_a_writing_tool_whose_annotations_cannot_be_read_counts_as_a_write(): void
    {
        $tool = new class extends ApiTool {
            protected function method(): string
            {
                return 'POST';
            }

            protected function path(): string
            {
                return '/probe';
            }

            public function annotations(): array
            {
                throw new \RuntimeException('broken attribute');
            }
        };
        Log::spy();

        $this->assertFalse((new ToolPolicy(['permission_mode' => 'full']))->readsOnly($tool::class));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Could not read the annotations of an MCP tool; treating it as a write.'
                && $context['tool'] === $tool::class
                && $context['error'] === 'broken attribute',
        )->once();
    }

    public function test_tools_named_in_a_configured_list_are_kept_whatever_their_case(): void
    {
        $policy = new ToolPolicy(['permission_mode' => 'full', 'toolsets' => ['none'], 'tools' => [' METRICS_LATEST ', '']]);

        $this->assertSame([MetricsLatestTool::class], $policy->filter([MetricsLatestTool::class, ProjectListSummaryTool::class]));
    }
}
