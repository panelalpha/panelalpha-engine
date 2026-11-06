<?php

namespace App\System\Project\Backup;

use App\Models\BackupItem;
use Illuminate\Support\Collection;

/** A restore's only/exclude filter: {files?: bool, volumes?: list<string>, databases?: list<string>}. */
final class ItemFilter
{
    public static function isNonEmpty(array $filter): bool
    {
        if (($filter['files'] ?? false) === true) {
            return true;
        }

        if (($filter['volumes'] ?? []) !== []) {
            return true;
        }

        if (($filter['databases'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, BackupItem>  $items
     * @return Collection<int, BackupItem>
     */
    public static function select(Collection $items, array $only, array $exclude): Collection
    {
        if (! self::isNonEmpty($only) && ! self::isNonEmpty($exclude)) {
            return $items;
        }

        if (self::isNonEmpty($only)) {
            return $items->filter(
                fn (BackupItem $item): bool => self::matches($item, $only),
            )->values();
        }

        return $items->filter(
            fn (BackupItem $item): bool => ! self::matches($item, $exclude),
        )->values();
    }

    private static function matches(BackupItem $item, array $filter): bool
    {
        $type = $item->details['type'] ?? null;
        $name = $item->details['name'] ?? null;

        return match ($type) {
            'files' => ($filter['files'] ?? false) === true,
            'volume' => is_string($name) && in_array($name, $filter['volumes'] ?? [], true),
            'database' => is_string($name) && in_array($name, $filter['databases'] ?? [], true),
            default => false,
        };
    }
}
