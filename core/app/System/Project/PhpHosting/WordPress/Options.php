<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named WordPress option operations for a PHP hosting WordPress install.
 *
 * List/read/update map to `wp option list|get|update`, so siteurl, home,
 * blogname, and blog_public are writable without their own routes. HTTP,
 * artisan, and MCP tools are adapters. Path resolution and the runner come
 * from WpCli; a non-zero exit becomes Exception.
 */
final class Options
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $result = $this->cli->runOrFail(['option', 'list', '--format=json']);

        return JsonOutput::decodeList($result['stdout']);
    }

    /**
     * @return array{option: string, value: string}
     */
    public function get(string $name): array
    {
        $result = $this->cli->runOrFail(['option', 'get', $name]);

        return [
            'option' => $name,
            'value' => trim($result['stdout']),
        ];
    }

    /**
     * @return array{option: string, value: string, message: string}
     */
    public function set(string $name, string $value): array
    {
        $result = $this->cli->runOrFail(['option', 'update', $name, $value]);

        return [
            'option' => $name,
            'value' => $value,
            'message' => trim($result['stdout']),
        ];
    }
}
