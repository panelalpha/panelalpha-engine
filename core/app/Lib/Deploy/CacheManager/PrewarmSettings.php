<?php

namespace App\Lib\Deploy\CacheManager;

use App\Support\EngineSetting;
use App\Support\EnvFile;
use InvalidArgumentException;

/**
 * Which catalogue images the host keeps ready, and the disk one prewarm may spend: {@see EngineSetting}s over
 * DEPLOY_PREWARM_IMAGES, DEPLOY_PREWARM_BUDGET and DEPLOY_PREWARM_RESERVE, since images.yaml is read-only. Read by
 * the next prewarm, weekly or `pae system:image:prewarm`; nothing is pulled or deleted when they change. Shared by
 * `pae configure prewarm` and the API.
 */
class PrewarmSettings
{
    /** @var array<string, string> setting => env key */
    public const ENV_KEYS = [
        'images' => 'DEPLOY_PREWARM_IMAGES',
        'budget' => 'DEPLOY_PREWARM_BUDGET',
        'reserve' => 'DEPLOY_PREWARM_RESERVE',
    ];

    /** The budget that lets a prewarm spend anything down to the reserve. */
    public const NO_BUDGET = 'none';

    public function __construct(private readonly ?EnvFile $env = null) {}

    /** One of images, budget or reserve, as the prewarm reads it. */
    public static function setting(string $which): EngineSetting
    {
        return new EngineSetting(self::ENV_KEYS[$which], 'deploy.prewarm_'.$which);
    }

    /** @return array{images: list<string>, budget: string, reserve: string} */
    public function current(): array
    {
        return [
            'images' => HostPrewarmPlan::selected(),
            'budget' => self::raw('budget'),
            'reserve' => self::raw('reserve'),
        ];
    }

    /** What is in force for one of them, empty for the images.yaml default. */
    public static function raw(string $which): string
    {
        $value = self::setting($which)->value();

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Everything the catalogue could warm, grouped by runtime so a minor sits beside its siblings; within a
     * runtime, the catalogue's own order.
     *
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    public function catalog(): array
    {
        $items = HostPrewarmPlan::available();
        $rank = array_flip(array_values(array_unique(array_column($items, 'runtime'))));
        $position = array_flip(array_column($items, 'id'));

        usort($items, static fn (array $a, array $b): int => [$rank[$a['runtime']], $position[$a['id']]] <=> [$rank[$b['runtime']], $position[$b['id']]]);

        return $items;
    }

    /** The value images.yaml, or the engine itself, gives a size left empty. */
    public function shipped(string $which): string
    {
        $declared = $which === 'budget' ? ImageCatalog::budget() : ImageCatalog::reserve();
        if ($declared !== null && $declared !== '') {
            return $declared;
        }

        return HostPrewarmPlan::formatBytes($which === 'budget'
            ? HostPrewarmPlan::FALLBACK_BUDGET_BYTES
            : HostPrewarmPlan::FALLBACK_RESERVE_BYTES);
    }

    /** The value in force for a size, as the operator would write it. */
    public function effective(string $which, string $value): string
    {
        return $value !== '' ? $value : $this->shipped($which);
    }

    /** Why $value cannot be the budget or the reserve, or null when it can; empty is the default. */
    public static function badSize(string $which, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ($which === 'budget' && strtolower($value) === self::NO_BUDGET)) {
            return null;
        }

        try {
            HostPrewarmPlan::parseBytes($value);
        } catch (InvalidArgumentException) {
            return $which === 'budget'
                ? 'A size like 6G or 512M, `none`, or empty for the default.'
                : 'A size like 10G or 512M, or empty for the default.';
        }

        return null;
    }

    /**
     * @param  array{images: list<string>, budget: string, reserve: string}  $state
     * @return array<string, string>
     */
    public static function envLines(array $state): array
    {
        return [
            self::ENV_KEYS['images'] => implode(',', $state['images']),
            self::ENV_KEYS['budget'] => $state['budget'],
            self::ENV_KEYS['reserve'] => $state['reserve'],
        ];
    }

    /**
     * Stores the whole state, what the wizard reviews and saves; an empty size stores the images.yaml default.
     *
     * @param  array{images: list<string>, budget: string, reserve: string}  $state
     * @return list<string> the env keys whose stored value changed
     */
    public function save(array $state): array
    {
        $lines = self::envLines($state);
        $changed = [];
        foreach (self::ENV_KEYS as $which => $env) {
            if (self::setting($which)->set($lines[$env])) {
                $changed[] = $env;
            }
        }

        return $changed;
    }

    /**
     * Applies each given field; a field left out stays, null puts it back to `.env-core` or images.yaml.
     *
     * @param  array{images?: ?list<string>, budget?: ?string, reserve?: ?string}  $changes
     * @return list<string> the env keys whose stored value changed
     */
    public function set(array $changes): array
    {
        $changed = [];
        foreach ($changes as $which => $value) {
            $stored = match (true) {
                $value === null => null,
                $which === 'images' => implode(',', $value),
                default => trim((string) $value),
            };
            if (self::setting($which)->set($stored)) {
                $changed[] = self::ENV_KEYS[$which];
            }
        }

        return $changed;
    }

    /**
     * @return array{
     *     images: array{value: list<string>, source: string, unknown: list<string>},
     *     budget: array{value: string, source: string, default: string},
     *     reserve: array{value: string, source: string, default: string},
     *     available: list<array{id: string, kind: string, runtime: string, why: string}>
     * }
     */
    public function toArray(): array
    {
        $current = $this->current();
        $catalog = $this->catalog();
        $source = fn (string $which): string => self::setting($which)->source($this->env);

        return [
            'images' => [
                'value' => $current['images'],
                'source' => $source('images'),
                'unknown' => array_values(array_diff($current['images'], array_column($catalog, 'id'))),
            ],
            'budget' => ['value' => $this->effective('budget', $current['budget']), 'source' => $source('budget'), 'default' => $this->shipped('budget')],
            'reserve' => ['value' => $this->effective('reserve', $current['reserve']), 'source' => $source('reserve'), 'default' => $this->shipped('reserve')],
            'available' => array_map(static fn (array $item): array => [
                'id' => $item['id'],
                'kind' => $item['kind'],
                'runtime' => $item['runtime'],
                'why' => $item['why'],
            ], $catalog),
        ];
    }
}
