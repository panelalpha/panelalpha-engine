<?php

namespace App\Lib\Deploy\CacheManager;

use App\Models\OperatorImage;

/**
 * Every image the host keeps for its projects, operator's then catalogue, each with whether the cache registry
 * holds it. `present` is null when the registry could not be read.
 */
final class SystemImageListing
{
    /** @var array<string, int>|null */
    private ?array $registryRefs = null;

    private bool $registryRead = false;

    public function __construct(
        private readonly CacheRegistry $registry,
        private readonly RegistryImageConfig $config,
    ) {}

    public static function make(): self
    {
        return new self(CacheRegistry::readOnly(), new RegistryImageConfig);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $entries = $this->operators();
        $selected = array_flip(HostPrewarmPlan::selected());
        foreach (HostPrewarmPlan::available() as $item) {
            $entries[] = $this->engine($item, isset($selected[$item['id']]));
        }

        return $entries;
    }

    /**
     * The operator's own images alone.
     *
     * @return list<array<string, mixed>>
     */
    public function operators(): array
    {
        $entries = [];
        foreach (OperatorImage::query()->orderBy('ref')->get() as $image) {
            $entries[] = $this->operator($image);
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function operator(OperatorImage $image): array
    {
        $present = $this->isPresent($image->ref);

        return array_merge($image->toApi(), [
            'digest' => $present === true ? $this->registry->digest($image->ref) : null,
            'present' => $present,
        ]);
    }

    /**
     * @param  array{id: string, ref: string, kind: string, runtime: string, why: string}  $item
     * @return array<string, mixed>
     */
    private function engine(array $item, bool $selected): array
    {
        $present = $this->isPresent($item['ref']);

        return [
            'id' => null,
            'owner' => 'engine',
            'ref' => $item['ref'],
            'source' => $item['kind'] === HostPrewarmPlan::KIND_BUILD ? OperatorImage::SOURCE_BUILD : OperatorImage::SOURCE_PULL,
            'why' => $item['why'],
            'runtime' => $item['runtime'],
            'prewarm_id' => $item['id'],
            'selected' => $selected,
            'present' => $present,
            'digest' => $present === true ? $this->registry->digest($item['ref']) : null,
            'size' => $present === true ? $this->config->cachedDownloadBytes($item['ref']) : null,
            'refreshed_at' => null,
        ];
    }

    private function isPresent(string $ref): ?bool
    {
        if (! $this->registryRead) {
            $refs = $this->registry->refs();
            $this->registryRefs = $refs === null ? null : array_flip($refs);
            $this->registryRead = true;
        }

        return $this->registryRefs === null ? null : isset($this->registryRefs[$ref]);
    }
}
