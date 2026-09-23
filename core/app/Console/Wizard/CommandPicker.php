<?php

namespace App\Console\Wizard;

use Closure;

use function Laravel\Prompts\multiselect;

/**
 * The ticking screens, without the question of where the ticks are stored.
 *
 * The global scope writes to `.env` and a token's scope to its abilities, but
 * both ask the operator the same thing — so each brings its own universe,
 * selection and header, and this knows nothing about either destination.
 */
class CommandPicker
{
    /**
     * @param array<string, array<int, string>> $groups  toolset => command names, the whole universe
     * @param Closure(): void                   $header  redraws the screen this picker draws on
     * @param Closure(string): string           $note    a command's short suffix, e.g. `read`
     * @param Closure(string): string|null      $groupNote a group's short suffix, or null for none
     * @param int                               $width     what a key's label is padded to
     */
    public function __construct(
        private readonly array $groups,
        private readonly Closure $header,
        private readonly Closure $note,
        private readonly ?Closure $groupNote = null,
        private readonly int $width = 34,
    ) {
    }

    /**
     * Whole groups, on or off. A group that was ticked and stays ticked is
     * left alone, or a visit here would undo every command unticked by hand.
     *
     * @param array<int, string> $selected
     * @return array<int, string>
     */
    public function groups(array $selected, string $label): array
    {
        ($this->header)();

        $selected = array_flip($selected);
        $before = $this->groupsFullyOff($selected);

        $after = array_map('strval', multiselect(
            label: $label,
            options: $this->groupOptions($selected),
            default: $before,
            scroll: 15,
            hint: 'Space to tick, Enter to accept. Ticking a group turns all of its commands on.',
        ));

        foreach ($this->groups as $toolset => $names) {
            $was = in_array($toolset, $before, true);
            $is = in_array($toolset, $after, true);

            if ($was === $is) {
                continue;
            }

            foreach ($names as $name) {
                if ($is) {
                    $selected[$name] = true;
                } else {
                    unset($selected[$name]);
                }
            }
        }

        return array_keys($selected);
    }

    /**
     * Every item, one checkbox each, in one list.
     *
     * No "which group?" first. Someone who came here came to find one thing,
     * and knowing which group it is filed under is a question they should not
     * have to answer to get at it. The list is ordered by group all the same,
     * so related items sit together while scrolling, and the names carry their
     * own resource — `domain_create`, `GET /projects/{username}/files` — so
     * nothing is lost by dropping the heading.
     *
     * The answer is the whole selection rather than one group's share of it,
     * because everything was on screen to be ticked.
     *
     * @param array<int, string> $selected
     * @return array<int, string>
     */
    public function commands(array $selected, string $label): array
    {
        ($this->header)();

        $selected = array_flip($selected);
        $options = [];

        foreach ($this->groups as $keys) {
            foreach ($keys as $key) {
                $options[$key] = sprintf('%-' . $this->width . 's %s', $key, ($this->note)($key));
            }
        }

        $picked = multiselect(
            label: $label,
            options: $options,
            default: array_values(array_filter(
                array_keys($options),
                fn (string $key): bool => isset($selected[$key])
            )),
            scroll: 20,
            hint: 'Space to tick, Enter to accept. Arrow keys scroll the whole list.',
        );

        return array_map('strval', $picked);
    }

    /**
     * The groups that have at least one command ticked — what "the group is
     * on" means when a group can be partly ticked.
     *
     * @param array<string, mixed> $selected keyed by command name
     * @return array<int, string>
     */
    private function groupsFullyOff(array $selected): array
    {
        $on = [];

        foreach ($this->groups as $toolset => $names) {
            foreach ($names as $name) {
                if (isset($selected[$name])) {
                    $on[] = $toolset;

                    break;
                }
            }
        }

        return $on;
    }

    /**
     * @param array<string, mixed> $selected keyed by command name
     * @return array<string, string>
     */
    private function groupOptions(array $selected): array
    {
        $options = [];

        foreach ($this->groups as $toolset => $names) {
            $on = array_filter($names, fn (string $n): bool => isset($selected[$n]));
            $note = $this->groupNote === null ? null : ($this->groupNote)($toolset);

            $options[$toolset] = sprintf(
                '%-18s %d of %d%s',
                $toolset,
                count($on),
                count($names),
                $note === null || $note === '' ? '' : '  ' . $note,
            );
        }

        return $options;
    }
}
