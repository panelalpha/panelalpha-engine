<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named maintenance-mode operations for a PHP hosting WordPress install.
 *
 * Read reports whether maintenance mode is active. Set turns it on or off,
 * then reads it back. Status treats a non-zero is-active exit as
 * enabled=false, not as a failure.
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
        $this->confirm($enabled);

        return [
            'enabled' => $enabled,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * WordPress writes .maintenance without checking that it could, so WP-CLI
     * reports success either way; only the state read back tells.
     */
    private function confirm(bool $enabled): void
    {
        if ($this->get()['enabled'] === $enabled) {
            return;
        }

        throw new Exception($enabled
            ? 'Maintenance mode is still off although WP-CLI reported success: WordPress could not create .maintenance in its install directory.'
            : 'Maintenance mode is still on although WP-CLI reported success: WordPress could not remove .maintenance from its install directory.');
    }
}
