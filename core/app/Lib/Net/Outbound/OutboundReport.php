<?php

namespace App\Lib\Net\Outbound;

/**
 * What the root broker reads of the outbound limits (scripts/tenant-egress-limits.sh): whether they are in place, the
 * settings in force, and each scan block and recent flood, SMTP or scan mark by the account or build that made it.
 */
final class OutboundReport
{
    /**
     * @param  array<string, int>  $settings
     * @param  list<array{owner: string, network: string, address: string, since: int, until: int}>  $blocks
     * @param  list<array{owner: string, network: string, kind: string, address: string, since: int, last: int}>  $marks
     */
    public function __construct(
        public readonly bool $active,
        public readonly array $settings,
        public readonly array $blocks,
        public readonly array $marks,
    ) {}

    public static function fromJson(string $printed): self
    {
        $decoded = json_decode(trim($printed), true);
        if (! is_array($decoded) || ! is_bool($decoded['active'] ?? null)) {
            throw new \UnexpectedValueException('The root broker answered the outbound limits report with no report.');
        }

        return new self(
            $decoded['active'],
            self::settings($decoded['settings'] ?? []),
            self::entries($decoded['blocks'] ?? [], ['owner', 'network', 'address'], ['since', 'until']),
            array_values(array_filter(
                self::entries($decoded['marks'] ?? [], ['owner', 'network', 'kind', 'address'], ['since', 'last']),
                static fn (array $mark): bool => in_array($mark['kind'], OutboundEvents::MARK_KINDS, true),
            )),
        );
    }

    /** @return array<string, int> */
    private static function settings(mixed $given): array
    {
        return is_array($given)
            ? array_filter($given, static fn (mixed $value, mixed $name): bool => is_string($name) && is_int($value), ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * @param  list<string>  $strings
     * @param  list<string>  $times
     * @return list<array<string, string|int>>
     */
    private static function entries(mixed $given, array $strings, array $times): array
    {
        $kept = [];
        foreach (is_array($given) ? $given : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $read = [];
            foreach ($strings as $field) {
                $read[$field] = is_string($entry[$field] ?? null) ? $entry[$field] : null;
            }
            foreach ($times as $field) {
                $read[$field] = is_int($entry[$field] ?? null) ? $entry[$field] : null;
            }
            if (! in_array(null, $read, true)) {
                $kept[] = $read;
            }
        }

        return $kept;
    }
}
