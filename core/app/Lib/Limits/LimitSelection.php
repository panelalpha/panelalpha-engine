<?php

namespace App\Lib\Limits;

/**
 * The limits one `project:limit:set` invocation asks to change, already
 * normalized. An option the caller did not pass is absent, which is what
 * separates "set it to no limit" from "leave it alone".
 */
final class LimitSelection
{
    /** @param array<string, int|float|null> $values keyed by ResourceLimit::$key */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Why the raw options cannot be accepted, keyed by limit. Checked against
     * the raw value, because normalize() has already folded -1 into null.
     *
     * @param  array<string, mixed>  $options console options, keyed by option name
     * @return array<string, string>
     */
    public static function rejections(array $options): array
    {
        $reasons = [];
        foreach (ResourceLimit::all() as $limit) {
            $raw = $options[$limit->option] ?? null;
            if ($raw === null) {
                continue;
            }
            $reason = $limit->rejectionReason($raw);
            if ($reason !== null) {
                $reasons[$limit->key] = $reason;
            }
        }

        return $reasons;
    }

    /** @param array<string, mixed> $options console options, keyed by option name */
    public static function fromOptions(array $options): self
    {
        $values = [];
        foreach (ResourceLimit::all() as $limit) {
            $raw = $options[$limit->option] ?? null;
            if ($raw === null) {
                continue;
            }
            $values[$limit->key] = $limit->normalize($raw);
        }

        return new self($values);
    }

    /** @param array<string, int|float|null> $values */
    public static function of(array $values): self
    {
        foreach (array_keys($values) as $key) {
            ResourceLimit::byKey($key);
        }

        return new self($values);
    }

    /** @return array<string, int|float|null> */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * True when any selected limit only takes effect after a rebuild. Keys in
     * $except were already applied live, so they do not ask for one.
     *
     * @param list<string> $except
     */
    public function needsRebuild(array $except = []): bool
    {
        foreach (array_keys($this->values) as $key) {
            if (!in_array($key, $except, true) && ResourceLimit::byKey($key)->needsRebuild) {
                return true;
            }
        }

        return false;
    }

    /** The `--foo=BAR or --baz=QUX` tail of the "at least one is required" error. */
    public static function optionListHint(): string
    {
        return implode(' or ', array_map(
            static fn (ResourceLimit $l): string => "`--{$l->option}={$l->argument}`",
            ResourceLimit::all()
        ));
    }
}
