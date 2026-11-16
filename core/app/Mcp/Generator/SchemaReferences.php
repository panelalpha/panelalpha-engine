<?php

namespace App\Mcp\Generator;

/**
 * Follows a property's `$ref`, or the single-entry `allOf` swagger-php wraps
 * one in, to the component schema it names.
 */
final class SchemaReferences
{
    private const PREFIX = '#/components/schemas/';

    private const MAX_DEPTH = 5;

    /** @param array<string, mixed> $schemas the document's `components.schemas` */
    public function __construct(private readonly array $schemas) {}

    /**
     * The property as the component it names, its own keys kept over the
     * component's; unchanged when it names none.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function resolved(array $definition, int $depth = 0): array
    {
        $ref = self::referenceOf($definition);
        $target = $ref === null || $depth > self::MAX_DEPTH ? null : ($this->schemas[$ref] ?? null);
        if (! is_array($target)) {
            return $definition;
        }
        unset($definition['$ref'], $definition['allOf']);

        return $this->resolved($definition + $target, $depth + 1);
    }

    /** @param array<string, mixed> $definition */
    private static function referenceOf(array $definition): ?string
    {
        $allOf = $definition['allOf'] ?? null;
        $ref = $definition['$ref'] ?? (is_array($allOf) && count($allOf) === 1 ? ($allOf[0]['$ref'] ?? null) : null);

        return is_string($ref) && str_starts_with($ref, self::PREFIX) ? substr($ref, strlen(self::PREFIX)) : null;
    }
}
