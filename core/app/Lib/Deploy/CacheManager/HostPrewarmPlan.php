<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\AccountImage;
use Illuminate\Container\Container;

/**
 * What to warm into the host image cache, and the disk it may cost. Accounts self-warm plain pulls, so the win is the
 * shared PHP base (minutes of compile per minor); candidates from images.yaml ({@see ImageCatalog}), selection and limits from `.env`.
 */
class HostPrewarmPlan
{
    public const KIND_BUILD = 'build';

    public const KIND_PULL = 'pull';

    public const KIND_ACCOUNT = 'account';

    /** Never spend the last of the disk: accounts live on the same filesystem. */
    public const FALLBACK_RESERVE_BYTES = 10737418240;

    /** Used only when the config declares no budget at all. */
    public const FALLBACK_BUDGET_BYTES = 6442450944;

    /**
     * The entries this host warms, highest priority first: only those selected
     * with `pae configure prewarm`, so none by default.
     *
     * @param  list<string>  $runtimes  limit to these runtimes; empty = all
     * @param  list<string>|null  $selected  entry ids; null = {@see selected()}
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
     * What every host warms ahead of {@see catalog()}, whatever was selected:
     * the image each account container runs.
     *
     * @return list<array<string, mixed>>
     */
    public static function required(): array
    {
        return [AccountImage::prewarmItem()];
    }

    /**
     * Everything images.yaml gives a `prewarm` priority: what an operator may
     * select from, in the order it would be warmed.
     *
     * @param  list<string>  $runtimes  limit to these runtimes; empty = all
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

    /** A prewarm setting as {@see PrewarmSettings} resolves it, or '' without a Laravel config (plain unit tests). */
    private static function setting(string $key): string
    {
        if (! Container::getInstance()->bound('config')) {
            return '';
        }

        return PrewarmSettings::raw(substr($key, strlen('prewarm_')));
    }

    /**
     * Take items in order while they fit. An absent required item and an unmeasured one are taken uncharged; the caller
     * re-reads free space before each item, so it overshoots by one item's size at most.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $sizes  ref => measured bytes, where known
     * @param  list<string>  $present  refs the host already has
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

        [$selected, $skipped] = self::takeWhileFits($items, $sizes, $have, $spendable);

        return ['selected' => $selected, 'skipped' => $skipped, 'spendable' => $spendable];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $sizes
     * @param  array<string, int>  $have
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function takeWhileFits(array $items, array $sizes, array $have, int $spendable): array
    {
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
            if (self::isRequired($item)) {
                $selected[] = $item;

                continue;
            }
            if (self::overBudget($bytes, $spent, $spendable)) {
                $item['reason'] = 'over budget';
                $skipped[] = $item;

                continue;
            }
            $spent += (int) $bytes;
            $selected[] = $item;
        }

        return [$selected, $skipped];
    }

    private static function overBudget(mixed $bytes, $spent, int $spendable): bool
    {
        return ($bytes !== null && $spent + $bytes > $spendable) || ($bytes === null && $spendable <= $spent);
    }

    /** @param  array<string, mixed>  $item */
    public static function isRequired(array $item): bool
    {
        return ($item['required'] ?? false) === true;
    }

    /**
     * Images we built, of a repository the catalogue names, that a changed recipe superseded. Compared on the fingerprint
     * segment, since a variant suffix does not end in it; a runtime whose fingerprint cannot be computed is kept (minutes to rebuild).
     *
     * @param  list<string>  $presentRefs
     * @return list<string>
     */
    public static function staleBuiltImages(array $presentRefs): array
    {
        $stale = [];
        foreach ($presentRefs as $ref) {
            if (! is_string($ref)) {
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
     * Still-valid images to give up until the reserve is met, the least-warmed first, so mild pressure costs one rarely
     * warmed image rather than the PHP set.
     *
     * @param  array<string, int>  $sizes  ref => bytes on disk, from `docker images`
     * @param  list<array<string, mixed>>  $catalog  what the warm plan asks for
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

        $candidates = self::rankedForReclaim($sizes, self::priorities($catalog));

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
     * @param  list<array<string, mixed>>  $catalog
     * @return array<string, int>
     */
    private static function priorities(array $catalog): array
    {
        $priority = [];
        foreach ($catalog as $item) {
            $ref = (string) ($item['ref'] ?? '');
            if ($ref !== '') {
                $priority[$ref] = (int) ($item['prewarm'] ?? 0);
            }
        }

        return $priority;
    }

    /**
     * Our images, least valuable first: unranked (a variant built on demand for one account) before ranked, and at equal
     * priority the bigger first, which buys more of the shortfall.
     *
     * @param  array<string, int>  $sizes
     * @param  array<string, int>  $priority
     * @return list<array{ref: string, bytes: int, priority: int}>
     */
    private static function rankedForReclaim(array $sizes, array $priority): array
    {
        $candidates = [];
        foreach ($sizes as $ref => $bytes) {
            if (! is_string($ref) || ! BuiltImage::isOurs($ref)) {
                continue;
            }
            $candidates[] = [
                'ref' => $ref,
                'bytes' => max(0, (int) $bytes),
                'priority' => $priority[$ref] ?? 0,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }

            return $b['bytes'] <=> $a['bytes'];
        });

        return $candidates;
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
            return round($bytes / 1073741824, 1).'G';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576).'M';
        }

        return $bytes.'B';
    }
}
