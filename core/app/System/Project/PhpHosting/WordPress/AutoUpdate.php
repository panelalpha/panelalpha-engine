<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Auto-updates of core (WP_AUTO_UPDATE_CORE: off|on|minor), plugins and themes (`auto-updates enable|disable --all`).
 * The read lists every plugin and theme too, since the aggregate cannot show a partly enabled type.
 * It reports the wp-config.php constants that block updates and never fails on an unknown core value.
 */
final class AutoUpdate
{
    private const CORE_CONSTANT = 'WP_AUTO_UPDATE_CORE';

    /** Constants that block automatic updates when true, in the order they are reported. */
    private const BLOCKING_CONSTANTS = ['DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED'];

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
     *     blocked_by: list<string>,
     * }
     */
    public function get(): array
    {
        $core = $this->readCore();
        $pluginItems = $this->readItems('plugin');
        $themeItems = $this->readItems('theme');

        $blockedBy = $core === null ? [self::CORE_CONSTANT] : [];
        foreach (self::BLOCKING_CONSTANTS as $name) {
            if ($this->isConstantTrue($name)) {
                $blockedBy[] = $name;
            }
        }

        return [
            'core' => $core ?? 'off',
            'plugins' => $this->aggregate($pluginItems),
            'themes' => $this->aggregate($themeItems),
            'plugin_items' => $pluginItems,
            'theme_items' => $themeItems,
            'blocked_by' => $blockedBy,
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

    /**
     * An undefined constant reads as minor: WordPress defaults to minor-only core updates. Read as JSON, since WP-CLI
     * otherwise echoes a false constant as an empty line, which would read as minor too.
     * Null means the constant has a value WordPress cannot use.
     */
    private function readCore(): ?string
    {
        $result = $this->cli->run(
            ['config', 'get', self::CORE_CONSTANT, '--type=constant', '--format=json'],
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

    private function mapCoreFromWp(string $raw): ?string
    {
        return match ($this->normalize($raw)) {
            'false', '0', 'off' => 'off',
            'true', '1', 'on' => 'on',
            'minor', '' => 'minor',
            default => null,
        };
    }

    /** True when the constant is defined and its value is true, 1 or on. */
    private function isConstantTrue(string $name): bool
    {
        $result = $this->cli->run(['config', 'get', $name, '--type=constant']);

        if (($result['exit_code'] ?? 1) !== 0) {
            return false;
        }

        return in_array($this->normalize($result['stdout']), ['true', '1', 'on'], true);
    }

    private function normalize(string $raw): string
    {
        return strtolower(trim($raw, " \t\n\r\0\x0B\"'"));
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
