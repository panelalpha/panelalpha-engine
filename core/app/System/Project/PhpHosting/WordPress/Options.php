<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named WordPress option operations for a PHP hosting WordPress install.
 *
 * List/read/update map to `wp option list|get|update`, so siteurl, home,
 * blogname, and blog_public are writable without their own routes.
 */
final class Options
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

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
        $result = $this->cli->runOrFail(['option', 'get', WpCli::positional($name)]);

        return [
            'option' => $name,
            'value' => trim($result['stdout']),
        ];
    }

    /**
     * The value goes JSON-encoded, so one starting with '-' is stored as sent
     * rather than read as a WP-CLI option.
     *
     * @return array{option: string, value: string, message: string}
     */
    public function set(string $name, string $value): array
    {
        $result = $this->cli->runOrFail([
            'option', 'update', WpCli::positional($name), json_encode($value, self::JSON_FLAGS), '--format=json',
        ]);

        return [
            'option' => $name,
            'value' => $value,
            'message' => trim($result['stdout']),
        ];
    }
}
