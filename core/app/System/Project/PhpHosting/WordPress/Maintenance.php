<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Set reads the mode back. Status takes a silent non-zero is-active exit as enabled=false;
 * one with an error, such as no WordPress at the path, fails like every other operation.
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
        $this->refuseFailedRun($result);

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
     * is-active answers "off" with a bare exit 1; an error message beside it means WP-CLI could not tell.
     * PHP warnings and deprecations from plugins are not error messages.
     *
     * @param  array{exit_code?: int, stdout?: string, stderr?: string}  $result
     */
    private function refuseFailedRun(array $result): void
    {
        if (($result['exit_code'] ?? 1) !== 0 && trim(JsonOutput::stripNoise((string) ($result['stderr'] ?? ''))) !== '') {
            throw new Exception($this->cli->failureMessage($result));
        }
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
