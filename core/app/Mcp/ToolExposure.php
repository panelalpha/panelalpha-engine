<?php

namespace App\Mcp;

use Laravel\Mcp\Server\Tool;

/**
 * One candidate answer to "which tools would this configuration expose?".
 *
 * `ToolPolicy` answers that for the configuration the process booted with; this
 * is a value object, so the wizard can hold and compare several candidates
 * before anything is written. `with()` returns a new instance.
 */
class ToolExposure
{
    /** Env variable => config key, in the order the wizard asks about them. */
    public const ENV_KEYS = [
        'permission_mode' => 'MCP_PERMISSION_MODE',
        'toolsets' => 'MCP_TOOLSETS',
        'tools' => 'MCP_TOOLS',
        'denied' => 'MCP_DENIED_TOOLS',
        'denied_regex' => 'MCP_DENIED_TOOLS_REGEX',
    ];

    /** Every toolset enabled, in the spelling config/mcp-tools.php documents. */
    public const ALL_TOOLSETS = 'all';

    /**
     * No toolset enabled. Needs a spelling of its own because `ToolPolicy`
     * reads an *empty* `MCP_TOOLSETS` as every group. Any non-group name would
     * do; `ToolExposureTest` asserts no real toolset is ever called this.
     */
    public const NO_TOOLSETS = 'none';

    /** @var array<string, string> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = array_map(
            static fn (mixed $v): string => trim((string) $v),
            array_intersect_key($config, self::ENV_KEYS)
        ) + array_fill_keys(array_keys(self::ENV_KEYS), '');
    }

    /** What this engine is serving right now. */
    public static function current(): self
    {
        return new self((array) config('mcp-tools', []));
    }

    /** @param array<string, string> $overrides */
    public function with(array $overrides): self
    {
        return new self($overrides + $this->config);
    }

    public function get(string $key): string
    {
        return $this->config[$key] ?? '';
    }

    /**
     * The `MCP_*` lines this exposure is. Empty keys are written too, so the
     * file shows the question was answered rather than skipped.
     *
     * @return array<string, string>
     */
    public function envLines(): array
    {
        $lines = [];

        foreach (self::ENV_KEYS as $key => $env) {
            $lines[$env] = $this->config[$key];
        }

        return $lines;
    }

    /**
     * The tick state behind `pae configure mcp` — what is selected, whatever
     * the ceiling then does with it.
     *
     * Not `exposedNames()`: a tool the ceiling holds back is still ticked, and
     * reading it as unticked would bake the ceiling into the denylist.
     *
     * @return array<int, string>
     */
    public function selectedNames(): array
    {
        return $this->with(['permission_mode' => ToolPolicy::MODE_FULL])->exposedNames();
    }

    /**
     * The same configuration, selecting exactly these tools.
     *
     * Three settings express one tick state; which to use is about what reads
     * best in `.env`. A fully ticked group is its name, an empty one its
     * absence, and a partial one whichever is shorter — group plus exceptions,
     * or its tools one by one. Ceiling and regex denylist pass through.
     *
     * @param array<int, string> $names
     */
    public function selecting(array $names): self
    {
        $policy = $this->policy();
        $wanted = array_flip($names);

        $toolsets = [];
        $tools = [];
        $denied = [];
        $groups = ToolRegistry::byToolset($policy);

        foreach ($groups as $toolset => $classes) {
            $all = array_map(fn (string $c): string => $policy->nameOf($c), $classes);
            $on = array_values(array_filter($all, fn (string $n): bool => isset($wanted[$n])));

            if ($on === []) {
                continue;
            }

            $off = array_values(array_diff($all, $on));

            if ($off === [] || count($off) <= count($on)) {
                $toolsets[] = $toolset;
                $denied = array_merge($denied, $off);

                continue;
            }

            $tools = array_merge($tools, $on);
        }

        return $this->with([
            'toolsets' => match (true) {
                $toolsets === [] => self::NO_TOOLSETS,
                count($toolsets) === count($groups) => self::ALL_TOOLSETS,
                default => implode(',', $toolsets),
            },
            'tools' => implode(',', $tools),
            'denied' => implode(',', $denied),
        ]);
    }

    /**
     * Settings whose value differs from another candidate's, by env name. The
     * comparison is of the lines written, so a difference `.env` cannot
     * express is not one.
     *
     * @return array<int, string>
     */
    public function diff(self $other): array
    {
        return array_keys(array_diff_assoc($this->envLines(), $other->envLines()));
    }

    public function policy(): ToolPolicy
    {
        return new ToolPolicy($this->config);
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function exposed(): array
    {
        return $this->policy()->filter(ToolRegistry::all());
    }

    /**
     * @return array<int, string>
     */
    public function exposedNames(): array
    {
        $policy = $this->policy();

        $names = array_map(fn (string $class): string => $policy->nameOf($class), $this->exposed());
        sort($names);

        return $names;
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function withheld(): array
    {
        return array_values(array_diff(ToolRegistry::all(), $this->exposed()));
    }

    /**
     * Every toolset, with how many of its tools this exposure keeps.
     *
     * @return array<string, array{total: int, exposed: int}>
     */
    public function toolsets(): array
    {
        $policy = $this->policy();
        $exposed = array_flip($this->exposed());

        $counts = [];

        foreach (ToolRegistry::byToolset($policy) as $toolset => $classes) {
            $counts[$toolset] = [
                'total' => count($classes),
                'exposed' => count(array_filter($classes, fn (string $c): bool => isset($exposed[$c]))),
            ];
        }

        return $counts;
    }

    /**
     * The toolsets named in the configuration, expanded: `all` — and the empty
     * value that means the same — become the full list, so a caller can show
     * them ticked rather than having to special-case the word.
     *
     * @return array<int, string>
     */
    public function enabledToolsets(): array
    {
        $named = $this->listOf('toolsets');
        $every = array_keys(ToolRegistry::byToolset($this->policy()));

        return ($named === [] || in_array(self::ALL_TOOLSETS, $named, true))
            ? $every
            : array_values(array_intersect($every, $named));
    }

    /** @return array<int, string> */
    public function extraTools(): array
    {
        return $this->listOf('tools');
    }

    /** @return array<int, string> */
    public function deniedPatterns(): array
    {
        return $this->listOf('denied');
    }

    /**
     * A comma list as the config files write one: trimmed, lowercased, empties
     * dropped. Same reading as `ToolPolicy` does of the same value.
     *
     * @return array<int, string>
     */
    private function listOf(string $key): array
    {
        $raw = trim($this->config[$key] ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $v): string => strtolower(trim($v)), explode(',', $raw)),
            fn (string $v): bool => $v !== ''
        ));
    }
}
