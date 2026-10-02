<?php

namespace App\Mcp\ToolSearch;

use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\ToolSearch;

/**
 * laravel/mcp's catalogue with an `offset` on search_tools, so a result with
 * `hasMore` can be followed past the first page.
 */
class PagedToolSearch extends ToolSearch
{
    /** @return array{PagedSearchTools, ExecuteTools} */
    public function tools(): array
    {
        return [new PagedSearchTools($this), new ExecuteTools($this, $this->maxToolCalls)];
    }

    /** @return array<string, mixed> */
    public function searchPage(string $query, int $limit, int $offset): array
    {
        // Rank everything with the package's own scoring, then page it under
        // the same output budget the package applies to one page.
        $budget = $this->maxOutputBytes;
        $this->maxOutputBytes = PHP_INT_MAX;
        try {
            $ranked = parent::search($query, PHP_INT_MAX)['tools'];
        } finally {
            $this->maxOutputBytes = $budget;
        }

        $page = array_slice($ranked, $offset, $limit);
        $tools = [];
        $size = $this->outputSize(['ok' => true, 'tools' => [], 'hasMore' => false, 'nextOffset' => PHP_INT_MAX]);

        foreach ($page as $tool) {
            $size += $this->outputSize($tool) + 1;

            if ($size > $this->maxOutputBytes) {
                if ($tools === []) {
                    return $this->outputLimitExceeded();
                }

                break;
            }

            $tools[] = $tool;
        }

        $next = $offset + count($tools);
        $hasMore = $next < count($ranked);

        return ['ok' => true, 'tools' => $tools, 'hasMore' => $hasMore, ...$hasMore ? ['nextOffset' => $next] : []];
    }
}
