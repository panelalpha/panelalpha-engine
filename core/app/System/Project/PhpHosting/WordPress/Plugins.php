<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named plugin operations for a PHP hosting WordPress install.
 */
final class Plugins
{
    use RunsExtensionCommands;

    private const WITHOUT_LOADING = ['--skip-plugins', '--skip-themes'];

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @param  string|null  $status  comma-separated statuses (active, inactive, must-use, ...); null = all
     * @return list<array<string, mixed>>
     */
    public function list(?string $fields = null, ?string $status = null): array
    {
        return $this->listWith(['plugin', 'list', '--format=json'], $fields, $status);
    }

    /**
     * One installed plugin's details. A plugin that is not installed is a 404,
     * any other failure keeps the WP-CLI output.
     *
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @return array<string, mixed>
     */
    public function get(string $slug, ?string $fields = null): array
    {
        $result = $this->cli->run($this->withFields(['plugin', 'get', WpCli::positional($slug), '--format=json'], $fields));
        if (($result['exit_code'] ?? 1) !== 0) {
            if (! $this->isInstalled($slug)['installed']) {
                throw new Exception("Plugin '{$slug}' is not installed.", 404);
            }

            throw new Exception($this->cli->failureMessage($result));
        }

        return JsonOutput::decode($result['stdout']);
    }

    /**
     * @return array{plugin: string, installed: bool}
     */
    public function isInstalled(string $slug): array
    {
        $exitCode = $this->installedExitCode(['plugin', 'is-installed', WpCli::positional($slug)]);

        return [
            'plugin' => $slug,
            'installed' => $exitCode === 0,
        ];
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function install(string $slug, bool $force = false): array
    {
        $args = ['plugin', 'install', WpCli::positional($slug)];
        if ($force) {
            $args[] = '--force';
        }

        return $this->mutation($slug, $args);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function activate(string $slug): array
    {
        return $this->mutation($slug, ['plugin', 'activate', WpCli::positional($slug)]);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function deactivate(string $slug): array
    {
        return $this->mutation($slug, ['plugin', 'deactivate', WpCli::positional($slug)]);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function update(string $slug): array
    {
        return $this->mutation($slug, ['plugin', 'update', WpCli::positional($slug)]);
    }

    /**
     * WordPress's own Delete: the plugin is deactivated and its uninstall runs before its files go, so it leaves no
     * dropin or constant behind and can drop its settings. A slug that is not installed was already deleted. A plugin
     * whose uninstall fails, such as one that breaks the site when loaded, has its files deleted without loading any
     * plugin, and the answer says its uninstall did not run.
     *
     * @return array{plugin: string, message: string}
     */
    public function delete(string $slug): array
    {
        $uninstall = $this->cli->run(['plugin', 'uninstall', WpCli::positional($slug), '--deactivate']);
        if ($uninstall['exit_code'] === 0) {
            return $this->mutationBody($slug, $uninstall['stdout']);
        }

        if (! $this->isInstalledWithoutLoading($slug)) {
            return ['plugin' => $slug, 'message' => "Plugin '{$slug}' is not installed, so nothing was deleted."];
        }

        return $this->deleteWithoutUninstall($slug, $uninstall);
    }

    private function isInstalledWithoutLoading(string $slug): bool
    {
        return $this->installedExitCode(['plugin', 'is-installed', WpCli::positional($slug), ...self::WITHOUT_LOADING]) === 0;
    }

    /**
     * @param  array{exit_code: int, stdout: string, stderr: string}  $uninstall
     * @return array{plugin: string, message: string}
     */
    private function deleteWithoutUninstall(string $slug, array $uninstall): array
    {
        $this->cli->runOrFail(['plugin', 'delete', WpCli::positional($slug), ...self::WITHOUT_LOADING]);

        return [
            'plugin' => $slug,
            'message' => "Plugin '{$slug}' was deleted, but its uninstall did not run: ".$this->cli->failureMessage($uninstall),
        ];
    }

    /**
     * Search the public wordpress.org plugin directory via WP-CLI.
     *
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @param  int|null  $perPage  results per page; null = the WP-CLI default (10)
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?string $fields = null, ?int $perPage = null): array
    {
        return $this->searchWith(['plugin', 'search', WpCli::positional($term), '--format=json'], $fields, $perPage);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    private function mutationBody(string $slug, string $stdout): array
    {
        return [
            'plugin' => $slug,
            'message' => trim($stdout),
        ];
    }
}
