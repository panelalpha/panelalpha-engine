<?php

namespace App\Lib\Findings;

/**
 * What a deploy or an inspection found: a stable code, a sentence that names no surface, and the facts behind it.
 * Stored as it is; hints are added only where a response is built ({@see FindingPresenter}).
 */
final class Finding
{
    /** A sentence stored before findings carried a code. */
    public const UNCLASSIFIED = 'unclassified';

    /**
     * @param  array<string, mixed>  $facts  named after the API parameters they would fill
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $facts = [],
    ) {}

    public static function fromStored(mixed $stored): ?self
    {
        if ($stored instanceof self) {
            return $stored;
        }
        if (is_string($stored)) {
            return trim($stored) === '' ? null : new self(self::UNCLASSIFIED, trim($stored));
        }
        if (! is_array($stored) || ! is_string($stored['code'] ?? null) || ! is_string($stored['message'] ?? null)) {
            return null;
        }

        $facts = $stored;
        unset($facts['code'], $facts['message'], $facts['hints']);

        return new self($stored['code'], $stored['message'], $facts);
    }

    /**
     * @return list<self>
     */
    public static function allStored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map(self::fromStored(...), $stored)));
    }

    /**
     * @param  list<self>  $findings
     * @return list<array<string, mixed>>
     */
    public static function storable(array $findings): array
    {
        return array_map(static fn (self $finding): array => $finding->toArray(), $findings);
    }

    /**
     * @param  list<self>  $findings
     */
    public static function sentence(array $findings): string
    {
        return implode(' | ', array_map(static fn (self $finding): string => $finding->message, $findings));
    }

    public function withMessage(string $message): self
    {
        return new self($this->code, $message, $this->facts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message] + $this->facts;
    }
}
