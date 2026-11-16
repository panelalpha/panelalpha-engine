<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named theme operations for a PHP hosting WordPress install.
 */
final class Themes
{
    use RunsExtensionCommands;

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @param  string|null  $status  comma-separated statuses (active, parent, inactive); null = all
     * @return list<array<string, mixed>>
     */
    public function list(?string $fields = null, ?string $status = null): array
    {
        return $this->listWith(['theme', 'list', '--format=json'], $fields, $status);
    }

    /**
     * One installed theme's details. A theme that is not installed is a 404,
     * any other failure keeps the WP-CLI output.
     *
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @return array<string, mixed>
     */
    public function get(string $slug, ?string $fields = null): array
    {
        $result = $this->cli->run($this->withFields(['theme', 'get', WpCli::positional($slug), '--format=json'], $fields));
        if (($result['exit_code'] ?? 1) !== 0) {
            if (! $this->isInstalled($slug)['installed']) {
                throw new Exception("Theme '{$slug}' is not installed.", 404);
            }

            throw new Exception($this->cli->failureMessage($result));
        }

        return JsonOutput::decode($result['stdout']);
    }

    /**
     * @return array{theme: string, installed: bool}
     */
    public function isInstalled(string $slug): array
    {
        $exitCode = $this->installedExitCode(['theme', 'is-installed', WpCli::positional($slug)]);

        return [
            'theme' => $slug,
            'installed' => $exitCode === 0,
        ];
    }

    /**
     * @return array{theme: string, message: string}
     */
    public function install(string $slug, bool $force = false): array
    {
        $args = ['theme', 'install', WpCli::positional($slug)];
        if ($force) {
            $args[] = '--force';
        }

        return $this->mutation($slug, $args);
    }

    /**
     * @return array{theme: string, message: string}
     */
    public function activate(string $slug): array
    {
        return $this->mutation($slug, ['theme', 'activate', WpCli::positional($slug)]);
    }

    /**
     * @return array{theme: string, message: string}
     */
    public function update(string $slug): array
    {
        return $this->mutation($slug, ['theme', 'update', WpCli::positional($slug)]);
    }

    /**
     * @return array{theme: string, message: string}
     */
    public function delete(string $slug): array
    {
        return $this->mutation($slug, ['theme', 'delete', WpCli::positional($slug)]);
    }

    /**
     * Search the public wordpress.org theme directory via WP-CLI.
     *
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @param  int|null  $perPage  results per page; null = the WP-CLI default (10)
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?string $fields = null, ?int $perPage = null): array
    {
        return $this->searchWith(['theme', 'search', WpCli::positional($term), '--format=json'], $fields, $perPage);
    }

    /**
     * @return array{theme: string, message: string}
     */
    private function mutationBody(string $slug, string $stdout): array
    {
        return [
            'theme' => $slug,
            'message' => trim($stdout),
        ];
    }
}
