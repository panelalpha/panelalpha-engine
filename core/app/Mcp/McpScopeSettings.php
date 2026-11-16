<?php

namespace App\Mcp;

use App\Support\EngineSetting;
use App\Support\EnvFile;

/**
 * What this engine offers AI assistants at all, for `pae configure mcp` and the API alike: {@see EngineSetting}s
 * over MCP_PERMISSION_MODE, MCP_TOOLSETS, MCP_TOOLS and MCP_DENIED_TOOLS. MCP_DENIED_TOOLS_REGEX stays in
 * `.env-core` alone. A token's own command list can only narrow this.
 *
 * The engine reads them on its next request. A connected client asked for the command list once, when it
 * connected, and keeps it until it reconnects.
 */
class McpScopeSettings
{
    /** The keys of {@see ToolExposure::ENV_KEYS} the wizard and the API change. */
    public const STORED = ['permission_mode', 'toolsets', 'tools', 'denied'];

    private const SELECTION = ['toolsets', 'tools', 'denied'];

    public function __construct(private readonly ?EnvFile $env = null) {}

    public static function setting(string $key): EngineSetting
    {
        return new EngineSetting(ToolExposure::ENV_KEYS[$key], 'mcp-tools.'.$key);
    }

    /**
     * config/mcp-tools.php as the engine applies it: each stored setting over what `.env-core` gave at boot.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $config = (array) config('mcp-tools', []);
        foreach (self::STORED as $key) {
            $stored = self::setting($key)->stored();
            if ($stored !== null) {
                $config[$key] = $stored;
            }
        }

        return $config;
    }

    public function current(): ToolExposure
    {
        return ToolExposure::current();
    }

    /**
     * Stores the exposure the wizard reviewed and saved.
     *
     * @return list<string> the env keys whose stored value changed
     */
    public function save(ToolExposure $next): array
    {
        return $this->store(array_combine(self::STORED, array_map($next->get(...), self::STORED)));
    }

    /**
     * The exposure with the ceiling and the selected commands given; one left out stays as it is.
     *
     * @param  array{permission_mode?: ?string, commands?: ?list<string>}  $changes
     */
    public function with(array $changes): ToolExposure
    {
        $next = $this->current();
        if (($changes['permission_mode'] ?? null) !== null) {
            $next = $next->with(['permission_mode' => $changes['permission_mode']]);
        }
        if (($changes['commands'] ?? null) !== null) {
            $next = $next->selecting($changes['commands']);
        }

        return $next;
    }

    /**
     * Applies each given field; one left out stays, null puts it back to `.env-core` or the default.
     *
     * @param  array{permission_mode?: ?string, commands?: ?list<string>}  $changes
     * @return list<string> the env keys whose stored value changed
     */
    public function set(array $changes): array
    {
        $next = $this->with($changes);
        $values = [];
        if (array_key_exists('permission_mode', $changes)) {
            $values['permission_mode'] = $changes['permission_mode'] === null ? null : $next->get('permission_mode');
        }
        if (array_key_exists('commands', $changes)) {
            foreach (self::SELECTION as $key) {
                $values[$key] = $changes['commands'] === null ? null : $next->get($key);
            }
        }

        return $this->store($values);
    }

    /** @return list<string> every command name this engine has, sorted */
    public static function commandNames(): array
    {
        $policy = ToolExposure::current()->policy();
        $names = array_map(static fn (string $class): string => $policy->nameOf($class), ToolRegistry::all());
        sort($names);

        return $names;
    }

    /**
     * @return array{
     *     permission_mode: array{value: string, source: string},
     *     commands: list<string>,
     *     commands_source: string,
     *     offered: list<string>,
     *     total: int,
     *     toolsets: array<string, array{total: int, exposed: int}>,
     *     denied_regex: ?string,
     *     env: array<string, string>
     * }
     */
    public function toArray(): array
    {
        $current = $this->current();

        return [
            'permission_mode' => ['value' => $current->policy()->mode(), 'source' => self::setting('permission_mode')->source($this->env)],
            'commands' => $current->selectedNames(),
            'commands_source' => $this->selectionSource(),
            'offered' => $current->exposedNames(),
            'total' => count(ToolRegistry::all()),
            'toolsets' => $current->toolsets(),
            'denied_regex' => $current->get('denied_regex') === '' ? null : $current->get('denied_regex'),
            'env' => $current->envLines(),
        ];
    }

    /** Where the selection came from: the most specific source any of its three settings has. */
    private function selectionSource(): string
    {
        $sources = array_map(fn (string $key): string => self::setting($key)->source($this->env), self::SELECTION);

        return current(array_intersect([EngineSetting::SETTING, EngineSetting::ENV], $sources)) ?: EngineSetting::DEFAULT;
    }

    /**
     * @param  array<string, ?string>  $values  key => value to store, null to remove
     * @return list<string>
     */
    private function store(array $values): array
    {
        $changed = [];
        foreach ($values as $key => $value) {
            if (self::setting($key)->set($value)) {
                $changed[] = ToolExposure::ENV_KEYS[$key];
            }
        }

        return $changed;
    }
}
