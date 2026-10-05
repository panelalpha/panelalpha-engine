<?php

namespace App\Mcp\ToolSearch;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\SearchTools;

class PagedSearchTools extends SearchTools
{
    public function __construct(private PagedToolSearch $pages)
    {
        parent::__construct($pages);
    }

    public function description(): string
    {
        return 'Search the tools available through execute_tools. Returns exact tool names, descriptions, and complete input schemas. An empty query browses the catalog; when hasMore is true, call again with offset set to nextOffset for the rest. '
            . $this->pages->areasLine();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'offset' => $schema->integer()->min(0)->description('Results to skip: the nextOffset of the previous page. Defaults to 0.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = $this->pages->searchPage(
            (string) $request->get('query', ''),
            (int) $request->get('limit', 10),
            (int) $request->get('offset', 0),
        );

        return $this->pages->response($result, ! $result['ok']);
    }
}
