<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named auto-update resource for a PHP hosting WordPress install.
 *
 * One read and one write over three fields: core (off|on|minor), plugins
 * (off|on), themes (off|on). Core maps to the WP_AUTO_UPDATE_CORE constant
 * (false|true, written raw, or the string 'minor'). Plugins and themes map to
 * enabling or disabling auto-updates across that type via `plugin|theme
 * auto-updates enable|disable --all`. The read also reports each plugin and
 * theme, since the aggregate cannot show a partly enabled type. A write sets
 * only the fields it is given and succeeds once every item has the requested
 * state, even when WP-CLI exits non-zero because some already had it.
 */
final class AutoUpdate
{
    private const CORE_CONSTANT = 'WP_AUTO_UPDATE_CORE';

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return array{
     *     core: string,
     *     plugins: string,
     *     themes: string,
     *     plugin_items: array<string, string>,
     *     theme_items: array<string, string>,
     * }
     */
    public function get(): array
    {
        $core = $this->readCore();
        $pluginItems = $this->readItems('plugin');
        $themeItems = $this->readItems('theme');

        return [
            'core' => $core,
            'plugins' => $this->aggregate($pluginItems),
            'themes' => $this->aggregate($themeItems),
            'plugin_items' => $pluginItems,
            'theme_items' => $themeItems,
        ];
    }

    /**
     * @param  array{core?: string, plugins?: string, themes?: string}  $fields
     * @return array{core?: string, plugins?: string, themes?: string}
     */
    public function set(array $fields): array
    {
        $written = [];
        if (isset($fields['core'])) {
            $this->writeCore($fields['core']);
            $written['core'] = $fields['core'];
        }
        if (isset($fields['plugins'])) {
            $this->writeTypeStatus('plugin', $fields['plugins']);
            $written['plugins'] = $fields['plugins'];
        }
        if (isset($fields['themes'])) {
            $this->writeTypeStatus('theme', $fields['themes']);
            $written['themes'] = $fields['themes'];
        }

        return $written;
    }

    /** An undefined constant reads as minor: WordPress defaults to minor-only core updates. */
    private function readCore(): string
    {
        $result = $this->cli->run(
            ['config', 'get', self::CORE_CONSTANT, '--type=constant'],
        );

        if (($result['exit_code'] ?? 1) !== 0) {
            return 'minor';
        }

        return $this->mapCoreFromWp(trim($result['stdout']));
    }

    /**
     * true/false are PHP literals and go in raw; 'minor' is a string, and
     * written raw it would be an undefined constant that breaks the site.
     */
    private function writeCore(string $mode): void
    {
        $args = match ($mode) {
            'off' => ['false', '--raw'],
            'on' => ['true', '--raw'],
            'minor' => ['minor'],
            default => throw new Exception("Invalid core auto-update mode: {$mode}."),
        };

        $this->cli->runOrFail(
            ['config', 'set', self::CORE_CONSTANT, $args[0], '--type=constant', ...array_slice($args, 1)],
        );
    }

    private function mapCoreFromWp(string $raw): string
    {
        $normalized = strtolower(trim($raw, " \t\n\r\0\x0B\"'"));

        return match ($normalized) {
            'false', '0', 'off' => 'off',
            'true', '1', 'on' => 'on',
            'minor', '' => 'minor',
            default => throw new Exception(
                "Unexpected WP_AUTO_UPDATE_CORE value: {$raw}.",
                500,
            ),
        };
    }

    /**
     * Per-item auto-update status, as WP-CLI reports it (enabled|disabled).
     *
     * @return array<string, string>
     */
    private function readItems(string $type): array
    {
        $result = $this->cli->runOrFail(
            [$type, 'auto-updates', 'status', '--all', '--format=json'],
        );

        $items = [];
        foreach (JsonOutput::decode($result['stdout']) as $row) {
            if (! is_array($row) || ! isset($row['name'])) {
                continue;
            }
            $items[(string) $row['name']] = strtolower((string) ($row['status'] ?? ''));
        }

        return $items;
    }

    /**
     * on when every item is enabled (or none are installed), otherwise off.
     *
     * @param  array<string, string>  $items
     */
    private function aggregate(array $items): string
    {
        foreach ($items as $status) {
            if (! $this->isEnabled($status)) {
                return 'off';
            }
        }

        return 'on';
    }

    /**
     * WP-CLI exits non-zero when some items already had the state ("Only
     * enabled 3 of 4"). That is success as long as all now have it.
     */
    private function writeTypeStatus(string $type, string $mode): void
    {
        $action = match ($mode) {
            'on' => 'enable',
            'off' => 'disable',
            default => throw new Exception("Invalid {$type} auto-update mode: {$mode}."),
        };

        $result = $this->cli->run([$type, 'auto-updates', $action, '--all']);
        if (($result['exit_code'] ?? 1) === 0) {
            return;
        }

        foreach ($this->readItems($type) as $status) {
            if ($this->isEnabled($status) !== ($mode === 'on')) {
                throw new Exception($this->cli->failureMessage($result));
            }
        }
    }

    private function isEnabled(string $status): bool
    {
        return $status === 'enabled' || $status === 'on';
    }
}
