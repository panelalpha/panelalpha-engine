<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named plugin operations for a PHP hosting WordPress install.
 */
final class Plugins
{
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
        $args = ['plugin', 'list', '--format=json'];
        if ($fields !== null) {
            $args[] = '--fields='.$fields;
        }
        if ($status !== null) {
            $args[] = '--status='.$status;
        }
        $result = $this->cli->runOrFail($args);

        return JsonOutput::decodeList($result['stdout']);
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
        $args = ['plugin', 'get', WpCli::positional($slug), '--format=json'];
        if ($fields !== null) {
            $args[] = '--fields='.$fields;
        }
        $result = $this->cli->run($args);
        if (($result['exit_code'] ?? 1) !== 0) {
            if (! $this->isInstalled($slug)['installed']) {
                throw new Exception("Plugin '{$slug}' is not installed.", 404);
            }

            throw new Exception($this->cli->failureMessage($result));
        }

        return JsonOutput::decode($result['stdout']);
    }

    /**
     * WP-CLI `plugin is-installed`: exit 1 is "not installed"; any other
     * non-zero exit (a fatal error loading the site) is a failure.
     *
     * @return array{plugin: string, installed: bool}
     */
    public function isInstalled(string $slug): array
    {
        $result = $this->cli->run(['plugin', 'is-installed', WpCli::positional($slug)]);
        $exitCode = $result['exit_code'] ?? 1;
        if ($exitCode !== 0 && $exitCode !== 1) {
            throw new Exception($this->cli->failureMessage($result));
        }

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
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($slug, $result['stdout']);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function activate(string $slug): array
    {
        $result = $this->cli->runOrFail(['plugin', 'activate', WpCli::positional($slug)]);

        return $this->mutationBody($slug, $result['stdout']);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function deactivate(string $slug): array
    {
        $result = $this->cli->runOrFail(['plugin', 'deactivate', WpCli::positional($slug)]);

        return $this->mutationBody($slug, $result['stdout']);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function update(string $slug): array
    {
        $result = $this->cli->runOrFail(['plugin', 'update', WpCli::positional($slug)]);

        return $this->mutationBody($slug, $result['stdout']);
    }

    /**
     * @return array{plugin: string, message: string}
     */
    public function delete(string $slug): array
    {
        $result = $this->cli->runOrFail(['plugin', 'delete', WpCli::positional($slug)]);

        return $this->mutationBody($slug, $result['stdout']);
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
        $args = ['plugin', 'search', WpCli::positional($term), '--format=json'];
        if ($fields !== null) {
            $args[] = '--fields='.$fields;
        }
        if ($perPage !== null) {
            $args[] = '--per-page='.$perPage;
        }
        $result = $this->cli->runOrFail($args);

        return JsonOutput::decodeList($result['stdout']);
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
