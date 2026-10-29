<?php

namespace App\Lib\Deploy\Template;

/**
 * The text of a generated deploy file, with its variable parts left open.
 *
 * Dockerfiles, entrypoints and helper scripts are files, not expressions, so
 * they live under `resources/deploy/templates/` where they can be read and
 * diffed as the thing they become. A generator's job is to answer the
 * questions a stub asks, never to concatenate one line at a time.
 *
 *   {{ name }}            the value, or nothing when it is null or false
 *   {{# name }}…{{/ name }}   kept when the value is present and non-empty
 *   {{^ name }}…{{/ name }}   kept when it is not
 *
 * A tag alone on its line takes the line with it when it renders to nothing,
 * and a multi-line value inherits that line's indentation, so a stub reads
 * like its output rather than like a string with holes in it.
 *
 * An unanswered placeholder is an error: a Dockerfile with `{{ port }}` still
 * in it fails at build time, on a host, hours later.
 *
 * No Laravel dependencies — unit-testable.
 */
final class Template
{
    private const BLOCK_LINE = '/^([^\S\r\n]*)\{\{([#^])\s*([A-Za-z0-9_]+)\s*\}\}[^\S\r\n]*\R(.*?)'
        .'^[^\S\r\n]*\{\{\/\s*\3\s*\}\}[^\S\r\n]*(?:\R|\z)/ms';

    private const BLOCK_INLINE = '/\{\{([#^])\s*([A-Za-z0-9_]+)\s*\}\}([^\r\n]*?)\{\{\/\s*\2\s*\}\}/';

    /**
     * Both variable forms in one alternation, so a single pass fills them.
     * Two passes would re-scan what the first one inserted, and a value that
     * happens to look like a tag is a value, not a tag.
     */
    private const VARIABLE = '/^([^\S\r\n]*)\{\{\s*([A-Za-z0-9_]+)\s*\}\}[^\S\r\n]*(\R|\z)'
        .'|\{\{\s*([A-Za-z0-9_]+)\s*\}\}/m';

    private function __construct(private readonly string $source) {}

    public static function named(string $name): self
    {
        return new self(TemplateLoader::stub($name));
    }

    public static function fromString(string $source): self
    {
        return new self($source);
    }

    /**
     * @param  array<string, string|int|float|bool|list<string>|null>  $values
     */
    public function render(array $values): string
    {
        return $this->fillVariables($this->expandBlocks($this->source, $values), $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function expandBlocks(string $text, array $values): string
    {
        do {
            $previous = $text;
            $text = $this->expandLineBlocks($text, $values);
            $text = $this->expandInlineBlocks($text, $values);
        } while ($text !== $previous);

        return $text;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function expandLineBlocks(string $text, array $values): string
    {
        return $this->replace(
            self::BLOCK_LINE,
            $text,
            fn (array $m): string => $this->keeps($m[2], $m[3], $values) ? $m[4] : ''
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function expandInlineBlocks(string $text, array $values): string
    {
        return $this->replace(
            self::BLOCK_INLINE,
            $text,
            fn (array $m): string => $this->keeps($m[1], $m[2], $values) ? $m[3] : ''
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fillVariables(string $text, array $values): string
    {
        return $this->replace(self::VARIABLE, $text, function (array $m) use ($values): string {
            $inline = $m[4] ?? '';

            return $inline === ''
                ? $this->lineValue($this->stringify($m[2], $values), $m[1], $m[3])
                : $this->stringify($inline, $values);
        });
    }

    /**
     * A tag alone on its line: the value takes the line's indentation, and an
     * empty one takes the line with it.
     */
    private function lineValue(string $value, string $indent, string $newline): string
    {
        return $value === '' ? '' : $this->indent($value, $indent).$newline;
    }

    /**
     * @param  callable(array<int, string>): string  $callback
     */
    private function replace(string $pattern, string $text, callable $callback): string
    {
        return (string) preg_replace_callback($pattern, $callback, $text);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function keeps(string $operator, string $name, array $values): bool
    {
        $present = $this->isPresent($this->value($name, $values));

        return $operator === '#' ? $present : ! $present;
    }

    private function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== [];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function stringify(string $name, array $values): string
    {
        $value = $this->value($name, $values);

        return match (true) {
            $value === null, $value === false => '',
            $value === true => '',
            is_array($value) => implode("\n", array_map(strval(...), $value)),
            is_scalar($value) => (string) $value,
            default => throw TemplateException::unsupportedValue($name, get_debug_type($value)),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function value(string $name, array $values): mixed
    {
        if (! array_key_exists($name, $values)) {
            throw TemplateException::unknownPlaceholder($name);
        }

        return $values[$name];
    }

    private function indent(string $value, string $indent): string
    {
        if ($indent === '') {
            return $value;
        }

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? $line : $indent.$line,
            explode("\n", $value)
        ));
    }
}
