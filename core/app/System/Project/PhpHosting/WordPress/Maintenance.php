<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named maintenance-mode operations for a PHP hosting WordPress install.
 *
 * Read reports whether maintenance mode is active. Set turns it on or off.
 * HTTP, artisan, and MCP tools are adapters. Path resolution and the runner
 * come from WpCli; a non-zero exit on set becomes Exception. Status treats
 * a non-zero is-active exit as enabled=false, not as a failure.
 */
final class Maintenance
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return array{enabled: bool}
     */
    public function get(): array
    {
        $result = $this->cli->run(['maintenance-mode', 'is-active']);

        return [
            'enabled' => ($result['exit_code'] ?? 1) === 0,
        ];
    }

    /**
     * @return array{enabled: bool, message: string}
     */
    public function set(bool $enabled): array
    {
        $action = $enabled ? 'activate' : 'deactivate';
        $result = $this->cli->runOrFail(['maintenance-mode', $action]);

        return [
            'enabled' => $enabled,
            'message' => trim($result['stdout']),
        ];
    }
}
