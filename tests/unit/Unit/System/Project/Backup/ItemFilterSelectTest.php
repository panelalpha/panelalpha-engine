<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\BackupItem;
use App\System\Project\Backup\ItemFilter;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * A restore's only/exclude filter picks backup items by kind and name; with neither, everything is restored.
 */
class ItemFilterSelectTest extends TestCase
{
    public function test_a_filter_naming_only_volumes_or_databases_is_not_empty(): void
    {
        $this->assertTrue(ItemFilter::isNonEmpty(['volumes' => ['dbdata']]));
        $this->assertTrue(ItemFilter::isNonEmpty(['databases' => ['alice_shop']]));
        $this->assertTrue(ItemFilter::isNonEmpty(['files' => true]));
        $this->assertFalse(ItemFilter::isNonEmpty(['files' => false, 'volumes' => [], 'databases' => []]));
    }

    public function test_without_a_filter_every_item_is_kept(): void
    {
        $items = $this->items();

        $this->assertSame($items, ItemFilter::select($items, [], ['files' => false]));
    }

    public function test_only_keeps_the_named_volume(): void
    {
        $selected = ItemFilter::select($this->items(), ['volumes' => ['dbdata']], ['files' => true]);

        $this->assertSame(['volume:dbdata'], $this->labels($selected));
    }

    public function test_exclude_drops_the_named_items_and_keeps_the_rest(): void
    {
        $selected = ItemFilter::select($this->items(), [], ['files' => true, 'databases' => ['alice_shop']]);

        $this->assertSame(['volume:dbdata', 'volume:uploads', 'database:alice_blog', 'unknown:'], $this->labels($selected));
    }

    public function test_an_item_of_an_unknown_kind_or_without_a_name_never_matches(): void
    {
        $selected = ItemFilter::select($this->items(), [], ['volumes' => ['dbdata', 'uploads'], 'databases' => ['alice_shop', 'alice_blog']]);

        $this->assertSame(['files:files', 'unknown:'], $this->labels($selected));
    }

    /** @return Collection<int, BackupItem> */
    private function items(): Collection
    {
        return collect([
            ['type' => 'files', 'name' => 'files'],
            ['type' => 'volume', 'name' => 'dbdata'],
            ['type' => 'volume', 'name' => 'uploads'],
            ['type' => 'database', 'name' => 'alice_shop'],
            ['type' => 'database', 'name' => 'alice_blog'],
            ['type' => 'unknown'],
        ])->map(static function (array $details): BackupItem {
            $item = new BackupItem;
            $item->details = $details;

            return $item;
        });
    }

    /**
     * @param  Collection<int, BackupItem>  $items
     * @return list<string>
     */
    private function labels(Collection $items): array
    {
        return $items->map(static fn (BackupItem $item): string => ($item->details['type'] ?? '').':'.($item->details['name'] ?? ''))->values()->all();
    }
}
