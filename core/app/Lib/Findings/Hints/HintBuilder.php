<?php

namespace App\Lib\Findings\Hints;

use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Support\Str;

/**
 * The REST, MCP and CLI forms of each hint that resolves a finding. The MCP form is derived from the REST operation,
 * so a tool's name or argument names cannot drift from the hint; a tool this engine does not expose gets none.
 */
final class HintBuilder
{
    private const CLI = 'pae';

    private const PLACEHOLDER = '/\{(\w+)\}/';

    /** @var array<string, string>|null */
    private static ?array $toolNames = null;

    /** @var array<string, class-string<ApiTool>>|null */
    private static ?array $toolClasses = null;

    public function __construct(private readonly ToolPolicy $policy = new ToolPolicy) {}

    /**
     * @param  array<string, mixed>  $facts  the finding's facts and the project it belongs to, as `username`
     * @param  list<string>  $present  every code in the same answer
     * @return list<array<string, mixed>>
     */
    public function for(string $code, array $facts, array $present = []): array
    {
        $hints = [];
        foreach (Hints::for($code) as $hint) {
            if (array_intersect($hint->unless(), $present) === []) {
                array_push($hints, ...$this->forms($hint, $this->values($hint, $facts), $facts));
            }
        }

        return $hints;
    }

    /**
     * A command may name what the answer is about, such as the project, though no API parameter of the hint does.
     *
     * @param  array<string, scalar|null>  $values
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function forms(Hint $hint, array $values, array $facts): array
    {
        return array_values(array_filter([
            self::rest($hint, $values),
            $this->mcp($hint, $values),
            self::cli($hint, $values + array_filter($facts, 'is_scalar')),
        ]));
    }

    /**
     * Every parameter the action takes, path ones first; null where nothing known can fill it, absent when that one is optional.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, scalar|null>
     */
    private function values(Hint $hint, array $facts): array
    {
        $templates = [];
        foreach (self::pathParams($hint) as $name) {
            $templates[$name] = '{'.$name.'}';
        }

        $values = [];
        foreach ([...$templates, ...$hint->params()] as $name => $template) {
            $values[$name] = self::resolve($template, $facts);
        }

        return array_filter(
            $values,
            static fn (mixed $value, string $name): bool => $value !== null || ! in_array($name, $hint->optional(), true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function resolve(mixed $template, array $facts): mixed
    {
        if (! is_string($template) || preg_match('/^\{(\w+)\}$/', $template, $match) !== 1) {
            return $template;
        }
        $value = $facts[$match[1]] ?? null;

        return is_scalar($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function pathParams(Hint $hint): array
    {
        if ($hint->rest() === null) {
            return [];
        }
        preg_match_all(self::PLACEHOLDER, $hint->rest(), $matches);

        return $matches[1];
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, mixed>|null
     */
    private static function rest(Hint $hint, array $values): ?array
    {
        if ($hint->rest() === null) {
            return null;
        }
        [$method, $path] = explode(' ', $hint->rest(), 2);
        $pathParams = array_intersect_key($values, array_flip(self::pathParams($hint)));
        $rest = array_diff_key($values, $pathParams);

        return ['type' => 'rest', 'action' => $hint->action(), 'method' => $method, 'path' => $path]
            + ($pathParams !== [] ? ['path_params' => $pathParams] : [])
            + ($rest !== [] ? [$method === 'GET' ? 'query' : 'body' => $rest] : []);
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, mixed>|null
     */
    private function mcp(Hint $hint, array $values): ?array
    {
        $class = $hint->rest() !== null ? self::toolClass($hint->rest()) : null;
        if ($class === null || $this->policy->filter([$class]) === []) {
            return null;
        }

        $tool = new $class;
        $arguments = [];
        foreach ($values as $name => $value) {
            $arguments[$tool->argument($name)] = $value;
        }

        return ['type' => 'mcp', 'action' => $hint->action(), 'tool' => $tool->name(), 'arguments' => $arguments];
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, mixed>|null
     */
    private static function cli(Hint $hint, array $values): ?array
    {
        if ($hint->cli() === null) {
            return null;
        }
        $command = (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $match): string => self::cliValue($match[1], $values[$match[1]] ?? null),
            $hint->cli(),
        );

        return ['type' => 'cli', 'action' => $hint->action(), 'command' => self::CLI.' '.$command];
    }

    private static function cliValue(string $name, mixed $value): string
    {
        if ($value === null) {
            return "<{$name}>";
        }
        $value = (string) $value;

        return preg_match('/^[\w.@:\/-]+$/', $value) === 1 ? $value : escapeshellarg($value);
    }

    /**
     * @return class-string<ApiTool>|null
     */
    private static function toolClass(string $operation): ?string
    {
        self::$toolNames ??= require app_path('Mcp/tool-names.php');
        $name = self::$toolNames[$operation] ?? null;
        if (! is_string($name)) {
            return null;
        }

        return self::toolClasses()[Str::studly($name).'Tool'] ?? null;
    }

    /**
     * @return array<string, class-string<ApiTool>>
     */
    private static function toolClasses(): array
    {
        if (self::$toolClasses === null) {
            self::$toolClasses = [];
            foreach (require app_path('Mcp/Tools/Api/generated-tools.php') as $class) {
                self::$toolClasses[class_basename($class)] = $class;
            }
        }

        return self::$toolClasses;
    }
}
