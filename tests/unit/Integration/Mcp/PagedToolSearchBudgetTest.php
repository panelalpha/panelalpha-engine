<?php

namespace Tests\Integration\Mcp;

use App\Mcp\Tools\Api\Projects\ProjectCreateTool;
use App\Mcp\Tools\MetricsLatestTool;
use App\Mcp\Tools\ProjectListSummaryTool;
use App\Mcp\ToolSearch\PagedToolSearch;
use Tests\TestCase;

/** A page of search results stays under the output budget: it stops early, or refuses when not even one tool fits. */
class PagedToolSearchBudgetTest extends TestCase
{
    public function test_a_page_whose_first_tool_is_over_the_budget_is_refused(): void
    {
        config(['mcp.tool_search.max_output_bytes' => 256]);

        $page = (new PagedToolSearch([ProjectCreateTool::class]))->searchPage('project', 5, 0);

        $this->assertFalse($page['ok']);
        $this->assertSame('The tool output exceeded 256 bytes.', $page['error']['message']);
    }

    public function test_a_page_stops_before_the_tool_that_would_pass_the_budget(): void
    {
        $tools = [MetricsLatestTool::class, ProjectListSummaryTool::class];
        $whole = (new PagedToolSearch($tools))->searchPage('metrics project summary latest', 5, 0);
        $this->assertCount(2, $whole['tools']);
        $firstOnly = strlen((string) json_encode(['ok' => true, 'tools' => [$whole['tools'][0]], 'hasMore' => true, 'nextOffset' => PHP_INT_MAX])) + 2;
        config(['mcp.tool_search.max_output_bytes' => max(256, $firstOnly)]);

        $page = (new PagedToolSearch($tools))->searchPage('metrics project summary latest', 5, 0);

        $this->assertTrue($page['ok']);
        $this->assertSame([$whole['tools'][0]['name']], array_column($page['tools'], 'name'));
        $this->assertTrue($page['hasMore']);
        $this->assertSame(1, $page['nextOffset']);
    }
}
