<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * What to warm into the host image cache before any customer deploys, and how
 * much disk it may cost.
 *
 * The engine already self-warms: an account that needs an image the host does
 * not have pulls it to the host on first use and seeds itself from there, so
 * prewarming a plain `docker pull` only saves that one host pull. What it
 * cannot self-warm cheaply is the shared PHP base, which compiles the baked
 * extension set from the PHP source tree — minutes per minor, paid by whoever
 * deploys that minor first.
 *
 * The candidates and their order come from `config/core/images.yaml`
 * ({@see ImageCatalog}); which of them this host warms, and the disk limits,
 * from `.env` via `pae configure prewarm`. This class is the arithmetic on
 * top: what fits, what is already there, and what to give back when the host
 * is under pressure.
 *
 * Nothing here estimates a size. The caller measures each absent image and
 * passes the sizes in; an image it could not measure is still warmed, and the
 * free-space check between items is what protects the reserve.
 */
class HostPrewarmPlan
{
    public const KIND_BUILD = 'build';
    public const KIND_PULL = 'pull';

    /** Never spend the last of the disk: accounts live on the same filesystem. */
    public const FALLBACK_RESERVE_BYTES = 10737418240; // 10 GiB

    /** Used only when the config declares no budget at all. */
    public const FALLBACK_BUDGET_BYTES = 6442450944;  // 6 GiB

    /**
     * The entries this host warms, highest priority first: only those selected
     * with `pae configure prewarm`, so none by default.
     *
     * @param list<string> $runtimes limit to these runtimes; empty = all
     * @param list<string>|null $selected entry ids; null = {@see selected()}
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    public static function catalog(array $runtimes = [], ?array $selected = null): array
    {
        $wanted = array_flip($selected ?? self::selected());

        return array_values(array_filter(
            self::available($runtimes),
            static fn (array $i): bool => isset($wanted[$i['id']])
        ));
    }

    /**
     * Everything images.yaml gives a `prewarm` priority: what an operator may
     * select from, in the order it would be warmed.
     *
     * @param list<string> $runtimes limit to these runtimes; empty = all
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    public static function available(array $runtimes = []): array
    {
        $items = ImageCatalog::prewarmed();
        if ($runtimes === []) {
            return $items;
        }

        $wanted = array_flip(array_map('strtolower', $runtimes));

        return array_values(array_filter(
            $items,
            static fn (array $i): bool => isset($wanted[$i['runtime']])
        ));
    }

    /**
     * Entry ids from `DEPLOY_PREWARM_IMAGES`; images.yaml is read-only.
     *
     * @return list<string>
     */
    public static function selected(): array
    {
        return self::parseList(self::setting('prewarm_images'));
    }

    /** @return list<string> */
    public static function parseList(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    /** The host's free-space floor: `.env`, then images.yaml. */
    public static function reserveBytes(): int
    {
        return self::parseBytes(self::setting('prewarm_reserve'))
            ?? self::parseBytes(ImageCatalog::reserve())
            ?? self::FALLBACK_RESERVE_BYTES;
    }

    /** What one run may spend, or null for "up to the reserve": `.env`, then images.yaml. */
    public static function budgetBytes(): ?int
    {
        $declared = self::setting('prewarm_budget');
        if ($declared === '') {
            $declared = ImageCatalog::budget();
        }
        if ($declared === null || $declared === '') {
            return self::FALLBACK_BUDGET_BYTES;
        }

        return strtolower($declared) === 'none' ? null : self::parseBytes($declared);
    }

    /** A `deploy.*` value, or '' without a Laravel config (plain unit tests). */
    private static function setting(string $key): string
    {
        if (!\Illuminate\Container\Container::getInstance()->bound('config')) {
            return '';
        }
        $value = config('deploy.' . $key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Take items in the configured order while they fit.
     *
     * An item whose size $sizes does not carry is taken without being charged
     * for — a build has no manifest to measure until it exists, and refusing
     * to warm the one image worth minutes because its size is unknown would
     * defeat the plan. The caller re-reads free space before each item and
     * stops there instead, so an unmeasured item can overshoot the budget by
     * its own size and no further.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, int> $sizes ref => measured bytes, where known
     * @param list<string> $present refs the host already has
     * @return array{selected: list<array<string, mixed>>, skipped: list<array<string, mixed>>, spendable: int}
     */
    public static function select(
        array $items,
        array $sizes,
        int $availableBytes,
        ?int $reserveBytes = null,
        ?int $budgetBytes = null,
        array $present = []
    ): array {
        $reserve = max(0, $reserveBytes ?? self::reserveBytes());
        $have = array_flip($present);
        $headroom = max(0, $availableBytes - $reserve);
        $spendable = $budgetBytes === null ? $headroom : min($headroom, max(0, $budgetBytes));

        $selected = [];
        $skipped = [];
        $spent = 0;
        foreach ($items as $item) {
            $ref = (string) $item['ref'];
            if (isset($have[$ref])) {
                $item['reason'] = 'already present';
                $skipped[] = $item;
                continue;
            }
            $bytes = $sizes[$ref] ?? null;
            $item['bytes'] = $bytes;
            if ($bytes !== null && $spent + $bytes > $spendable) {
                $item['reason'] = 'over budget';
                $skipped[] = $item;
                continue;
            }
            if ($bytes === null && $spendable <= $spent) {
                $item['reason'] = 'over budget';
                $skipped[] = $item;
                continue;
            }
            $spent += (int) $bytes;
            $selected[] = $item;
        }

        return ['selected' => $selected, 'skipped' => $skipped, 'spendable' => $spendable];
    }

    /**
     * Images we built that a current recipe would no longer produce.
     *
     * A tag carries the recipe it was built from, so a changed recipe builds a
     * new image instead of serving a stale one — which also means the
     * superseded image lingers until something removes it.
     *
     * The repository table comes from the catalogue, so an image the config
     * says the engine builds is an image this can reclaim. Comparison is on
     * the fingerprint *segment*, not the end of the string: a current base
     * with a variant suffix does not end in its own fingerprint, and calling
     * that stale deletes every variant on every run.
     *
     * A runtime whose current fingerprint cannot be computed is left alone:
     * "cannot judge" has to mean keep, since this deletes images that cost
     * minutes to rebuild.
     *
     * @param list<string> $presentRefs
     * @return list<string>
     */
    public static function staleBuiltImages(array $presentRefs): array
    {
        $stale = [];
        foreach ($presentRefs as $ref) {
            if (!is_string($ref)) {
                continue;
            }
            $parts = BuiltImage::parts($ref);
            if ($parts === null) {
                continue;
            }
            $current = BuiltImage::currentFingerprint($parts['runtime']);
            if ($current !== null && $parts['fingerprint'] !== $current) {
                $stale[] = $ref;
            }
        }

        return $stale;
    }

    /**
     * Current images worth giving up to get back under the reserve — not "is
     * this obsolete" but "these are all still valid, which do we least mind
     * rebuilding".
     *
     * Ranked by the same order the warm plan spends in, and dropped from the
     * bottom until the reserve is met, so a host under mild pressure gives up
     * one rarely-warmed image rather than its PHP set. Everything named can be
     * rebuilt; the trade is disk now against a slower deploy later.
     *
     * @param array<string, int> $sizes ref => bytes on disk, from `docker images`
     * @param list<array<string, mixed>> $catalog what the warm plan asks for
     * @return list<string> least valuable first
     */
    public static function reclaimableBuiltImages(
        array $sizes,
        int $availableBytes,
        ?int $reserveBytes = null,
        array $catalog = []
    ): array {
        $shortfall = max(0, ($reserveBytes ?? self::reserveBytes()) - $availableBytes);
        if ($shortfall === 0) {
            return [];
        }

        $priority = [];
        foreach ($catalog as $item) {
            $ref = (string) ($item['ref'] ?? '');
            if ($ref !== '') {
                $priority[$ref] = (int) ($item['prewarm'] ?? 0);
            }
        }

        $candidates = [];
        foreach ($sizes as $ref => $bytes) {
            if (!is_string($ref) || !BuiltImage::isOurs($ref)) {
                continue;
            }
            $candidates[] = [
                'ref' => $ref,
                'bytes' => max(0, (int) $bytes),
                // Unranked means the config never asked for it: a variant
                // built on demand for one account, and the first to give up.
                'priority' => $priority[$ref] ?? 0,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }

            // Same priority: the bigger one buys more of the shortfall.
            return $b['bytes'] <=> $a['bytes'];
        });

        $freed = 0;
        $drop = [];
        foreach ($candidates as $candidate) {
            if ($freed >= $shortfall) {
                break;
            }
            $drop[] = $candidate['ref'];
            $freed += $candidate['bytes'];
        }

        return $drop;
    }

    /**
     * Accept "6G", "512M", "1500000000". Null for an unset option.
     */
    public static function parseBytes(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $value, $m) !== 1) {
            throw new \InvalidArgumentException("Not a byte size: {$value}");
        }
        $multiplier = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824, 't' => 1099511627776];

        return (int) round((float) $m[1] * $multiplier[strtolower($m[2])]);
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '?';
        }
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . 'G';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576) . 'M';
        }

        return $bytes . 'B';
    }
}
