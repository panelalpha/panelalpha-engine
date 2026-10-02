<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Support\EnvFile;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Which catalogue images the host keeps ready, and the disk the prewarm may
 * spend. None by default. Written to `.env`: images.yaml is read-only.
 */
class PrewarmSection implements Section
{
    use KeepsAReceipt;

    /** @var array<string, string> setting => env key */
    private const ENV_KEYS = [
        'images' => 'DEPLOY_PREWARM_IMAGES',
        'budget' => 'DEPLOY_PREWARM_BUDGET',
        'reserve' => 'DEPLOY_PREWARM_RESERVE',
    ];

    public static function key(): string
    {
        return 'prewarm';
    }

    public static function label(): string
    {
        return 'Prewarmed images — base images the host keeps before any deploy';
    }

    public static function hint(): string
    {
        return 'Which images are built or pulled ahead of time, and the disk they may use.';
    }

    public function run(bool $dryRun): int
    {
        $catalog = $this->catalog();
        $current = $this->current();
        $next = $current;

        while (true) {
            switch ($this->menu($catalog, $current, $next, $dryRun)) {
                case 'images':
                    $next['images'] = $this->pickImages($catalog, $current, $next);
                    break;

                case 'none':
                    $next['images'] = [];
                    break;

                case 'budget':
                    $next['budget'] = $this->askSize($catalog, $current, $next, 'budget');
                    break;

                case 'reserve':
                    $next['reserve'] = $this->askSize($catalog, $current, $next, 'reserve');
                    break;

                case 'save':
                    $done = $this->save($catalog, $current, $next, $dryRun);

                    if ($done !== null) {
                        return $done;
                    }

                    break;

                default:
                    if ($this->same($current, $next) || confirm(
                        label: 'Leave without saving? Your changes will be thrown away.',
                        default: false,
                    )) {
                        return 0;
                    }
            }
        }
    }

    /** @return array{images: list<string>, budget: string, reserve: string} */
    private function current(): array
    {
        return [
            'images' => HostPrewarmPlan::selected(),
            'budget' => trim((string) config('deploy.prewarm_budget', '')),
            'reserve' => trim((string) config('deploy.prewarm_reserve', '')),
        ];
    }

    /**
     * Everything the catalogue could warm, grouped by runtime so a minor sits
     * beside its siblings; within a runtime, the catalogue's own order.
     *
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    private function catalog(): array
    {
        $items = HostPrewarmPlan::available();
        $rank = array_flip(array_values(array_unique(array_column($items, 'runtime'))));
        $position = array_flip(array_column($items, 'id'));

        usort($items, static fn (array $a, array $b): int
            => [$rank[$a['runtime']], $position[$a['id']]] <=> [$rank[$b['runtime']], $position[$b['id']]]);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $current
     * @param array{images: list<string>, budget: string, reserve: string} $next
     */
    private function header(array $catalog, array $current, array $next): void
    {
        $lines = ['Now:         ' . $this->summary($catalog, $current)];

        if (!$this->same($current, $next)) {
            $lines[] = 'Your change: ' . $this->summary($catalog, $next) . '  (unsaved)';
        }

        Screen::draw(self::label(), implode("\n", $lines));
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $state
     */
    private function summary(array $catalog, array $state): string
    {
        return sprintf(
            '%d of %d images, budget %s, reserve %s',
            $this->selectedCount($catalog, $state['images']),
            count($catalog),
            $this->effective('budget', $state['budget']),
            $this->effective('reserve', $state['reserve']),
        );
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $current
     * @param array{images: list<string>, budget: string, reserve: string} $next
     */
    private function menu(array $catalog, array $current, array $next, bool $dryRun): string
    {
        $this->header($catalog, $current, $next);

        $changed = !$this->same($current, $next);
        $options = [
            'images' => sprintf('%-10s %d of %d selected', 'Images', $this->selectedCount($catalog, $next['images']), count($catalog)),
        ];
        if ($next['images'] !== []) {
            $options['none'] = 'Select none — warm nothing (the default)';
        }
        $options['budget'] = sprintf('%-10s %s', 'Budget', $this->describe('budget', $next['budget']));
        $options['reserve'] = sprintf('%-10s %s', 'Reserve', $this->describe('reserve', $next['reserve']));
        $options['save'] = match (true) {
            $dryRun => 'Review it — this is a dry run, so nothing will be written',
            !$changed => 'Review and save — nothing has changed yet',
            default => 'Review and save',
        };
        $options['back'] = $changed ? 'Back, throwing these changes away' : 'Back';

        return (string) select(
            label: 'What should the host keep ready?',
            options: $options,
            default: $changed ? 'save' : 'images',
            scroll: count($options),
            hint: 'An image not selected is built or pulled by the first deploy that needs it.',
        );
    }

    /**
     * One checkbox per image. Selected ids the catalogue no longer has are
     * kept, so an image someone chose comes back if a release restores it.
     *
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $current
     * @param array{images: list<string>, budget: string, reserve: string} $next
     * @return list<string>
     */
    private function pickImages(array $catalog, array $current, array $next): array
    {
        $this->header($catalog, $current, $next);

        if ($catalog === []) {
            note('config/core/images.yaml gives no image a prewarm priority, so there is nothing to select.', 'warning');
            pause('Press enter to carry on...');

            return $next['images'];
        }

        $width = max(array_map('strlen', array_column($catalog, 'id')));
        $options = [];
        foreach ($catalog as $item) {
            $options[$item['id']] = sprintf('%-' . $width . 's  %-5s  %s', $item['id'], $item['kind'], $item['why']);
        }

        $known = array_keys($options);
        $picked = array_map('strval', multiselect(
            label: 'Which images should the host keep ready?',
            options: $options,
            default: array_values(array_intersect($known, $next['images'])),
            scroll: 20,
            hint: 'Space to tick, Enter to accept. "build" images compile for minutes; "pull" ones download.',
        ));

        return array_values(array_merge($picked, array_diff($next['images'], $known)));
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $current
     * @param array{images: list<string>, budget: string, reserve: string} $next
     */
    private function askSize(array $catalog, array $current, array $next, string $which): string
    {
        $this->header($catalog, $current, $next);

        note($which === 'budget'
            ? 'The most disk one prewarm run may spend. A size like 6G, or `none` for anything down to the reserve.'
            : 'Free disk the prewarm never takes the host below. Accounts live on the same filesystem.');

        return trim(text(
            label: ucfirst($which),
            default: $next[$which],
            placeholder: 'empty = ' . $this->shipped($which) . ' from images.yaml',
            validate: fn (string $v): ?string => $this->badSize($which, $v),
        ));
    }

    private function badSize(string $which, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ($which === 'budget' && strtolower($value) === 'none')) {
            return null;
        }

        try {
            HostPrewarmPlan::parseBytes($value);
        } catch (\InvalidArgumentException $e) {
            return $which === 'budget'
                ? 'A size like 6G or 512M, `none`, or empty for the default.'
                : 'A size like 10G or 512M, or empty for the default.';
        }

        return null;
    }

    /** The value in force for a setting, as the operator would write it. */
    private function effective(string $which, string $value): string
    {
        return $value !== '' ? $value : $this->shipped($which);
    }

    private function describe(string $which, string $value): string
    {
        return $value !== '' ? $value : $this->shipped($which) . ' (images.yaml default)';
    }

    private function shipped(string $which): string
    {
        $declared = $which === 'budget' ? ImageCatalog::budget() : ImageCatalog::reserve();
        if ($declared !== null && $declared !== '') {
            return $declared;
        }

        return HostPrewarmPlan::formatBytes($which === 'budget'
            ? HostPrewarmPlan::FALLBACK_BUDGET_BYTES
            : HostPrewarmPlan::FALLBACK_RESERVE_BYTES);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array{images: list<string>, budget: string, reserve: string} $current
     * @param array{images: list<string>, budget: string, reserve: string} $next
     * @return int|null an exit code when done, or null to go back to the menu
     */
    private function save(array $catalog, array $current, array $next, bool $dryRun): ?int
    {
        $this->header($catalog, $current, $next);

        $lines = $this->envLines($next);
        note(implode("\n", array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($lines),
            $lines
        )));

        if ($dryRun) {
            note('Nothing was written: this was a dry run. Drop --dry-run to apply it.', 'warning');
            $this->receipt = ['A dry run of ' . self::key() . ' would have written:'];
            foreach ($lines as $key => $value) {
                $this->receipt[] = $key . '=' . $value;
            }
            pause('Press enter to carry on...');

            return 0;
        }

        if ($this->same($current, $next)) {
            note('Nothing to save: this is what the host already uses.', 'warning');
            pause('Press enter to carry on...');

            return null;
        }

        $env = EnvFile::current();
        if (!confirm(label: sprintf('Write this to %s?', $env->path()), default: true)) {
            note('Nothing was written. Your changes are still here.', 'warning');
            pause('Press enter to carry on...');

            return null;
        }

        try {
            $env->set($lines);
        } catch (Throwable $e) {
            error($e->getMessage());

            return 1;
        }

        // This process booted with the old values; the menu may be re-entered.
        config([
            'deploy.prewarm_images' => $lines[self::ENV_KEYS['images']],
            'deploy.prewarm_budget' => $next['budget'],
            'deploy.prewarm_reserve' => $next['reserve'],
        ]);

        $this->receipt = ['Prewarm: ' . $this->summary($catalog, $next) . '.'];
        note($this->receipt[0], 'info');

        if (app()->configurationIsCached()) {
            note('The cached configuration was built from the old values — run `pae config:cache` to rebuild it.', 'warning');
        }

        note(
            "Takes effect on the next prewarm: weekly, Sunday 03:30, or now with `pae system:image:prewarm`.\n"
            . "An image you deselected is not deleted now. The daily `system:image:prune` removes it from the host\n"
            . 'once no deploy has used it for DEPLOY_HOST_IMAGE_RETENTION, and the weekly prewarm drops it from the cache registry.',
            'warning'
        );
        pause('Press enter to carry on...');

        return 0;
    }

    /**
     * @param array{images: list<string>, budget: string, reserve: string} $state
     * @return array<string, string>
     */
    private function envLines(array $state): array
    {
        return [
            self::ENV_KEYS['images'] => implode(',', $state['images']),
            self::ENV_KEYS['budget'] => $state['budget'],
            self::ENV_KEYS['reserve'] => $state['reserve'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param list<string> $selected
     */
    private function selectedCount(array $catalog, array $selected): int
    {
        $wanted = array_flip($selected);

        return count(array_filter($catalog, static fn (array $i): bool => isset($wanted[$i['id']])));
    }

    /**
     * @param array{images: list<string>, budget: string, reserve: string} $a
     * @param array{images: list<string>, budget: string, reserve: string} $b
     */
    private function same(array $a, array $b): bool
    {
        sort($a['images']);
        sort($b['images']);

        return $a === $b;
    }
}
