<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named search-replace for a PHP hosting WordPress install.
 *
 * Maps to `wp search-replace {search} {replacement} ...extra`. Extra args are
 * forwarded as-is (e.g. `--dry-run`, table names, `--skip-columns=guid`).
 * HTTP, artisan, and MCP tools are adapters. Path resolution and the runner
 * come from WpCli; a non-zero exit becomes Exception.
 */
final class SearchReplace
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  list<string>  $extra
     * @return array{search: string, replacement: string, message: string}
     */
    public function run(string $search, string $replacement, array $extra = []): array
    {
        $args = array_merge(['search-replace', $search, $replacement], $extra);
        $result = $this->cli->runOrFail($args);

        return [
            'search' => $search,
            'replacement' => $replacement,
            'message' => trim($result['stdout']),
        ];
    }
}
