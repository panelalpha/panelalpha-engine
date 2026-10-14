<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\CommandPicker;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Support\EnvFile;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Which of the engine's MCP commands an AI assistant may see and call. The
 * engine API is root-equivalent, so this decides between a read-only view of
 * the server and the ability to delete a hosting account.
 *
 * The operator ticks commands, one checkbox each, and
 * {@see ToolExposure::selecting()} works out which env variables carry the
 * decision. The ceiling is not a tick: it limits what any ticked command may do.
 *
 * Documented in full in docs/internal/mcp.md.
 */
class McpGlobalScopeSection implements Section
{
    use KeepsAReceipt;

    public static function key(): string
    {
        return 'mcp-global';
    }

    public static function label(): string
    {
        return 'MCP tokens — for AI assistants  ·  Global scope';
    }

    public static function hint(): string
    {
        return 'What this engine offers assistants at all.';
    }

    /**
     * The menu an operator lands on and returns to after every answer. Answers
     * accumulate in `$next` against an unchanged `$current`, and nothing is
     * written until the operator saves; a declined save keeps the ticks.
     */
    public function run(bool $dryRun): int
    {
        $current = ToolExposure::current();
        $next = $current;

        while (true) {
            switch ($this->menu($current, $next, $dryRun)) {
                case 'groups':
                    $next = $this->tickGroups($current, $next);
                    break;

                case 'commands':
                    $next = $this->tickCommands($current, $next);
                    break;

                case 'ceiling':
                    $next = $next->with(['permission_mode' => $this->askCeiling($current, $next)]);
                    break;

                case 'save':
                    $done = $this->review($current, $next, $dryRun);
                    if ($done !== null) {
                        return $done;
                    }

                    break;

                default:
                    if ($this->mayLeave($current, $next)) {
                        return 0;
                    }
            }
        }
    }

    /**
     * Clear the screen and put back what stands above every prompt: what the
     * engine is serving, and what the ticks so far would change it to. Drawn
     * before each question, because each question is drawn over the last one.
     */
    private function header(ToolExposure $current, ToolExposure $next): void
    {
        Screen::draw(self::label(), implode("\n", $this->statusLines($current, $next)));

        $this->warnAboutDeniedRegex($current);
    }

    /**
     * The ceiling shown is the policy's reading of the setting: a value it
     * cannot use is a readonly engine, whatever the file says.
     *
     * @return list<string>
     */
    private function statusLines(ToolExposure $current, ToolExposure $next): array
    {
        $total = count(ToolRegistry::all());

        $lines = [sprintf(
            'Serving now: %d of %d commands, ceiling %s.',
            count($current->exposed()),
            $total,
            $current->policy()->mode(),
        )];

        if ($next->diff($current) !== []) {
            $lines[] = sprintf(
                'Your ticks:  %d of %d commands, ceiling %s.  (unsaved)',
                count($next->exposed()),
                $total,
                $next->policy()->mode(),
            );
        }

        return $lines;
    }

    /**
     * The denylist regex is not a tick and is not asked about here, but it
     * outlives this run; say so, or the wizard would look like it had lost it.
     */
    private function warnAboutDeniedRegex(ToolExposure $current): void
    {
        if (trim($current->get('denied_regex')) === '') {
            return;
        }

        warning(sprintf(
            "MCP_DENIED_TOOLS_REGEX=%s is also in force, and this wizard leaves it alone.\n"
            . 'Commands it matches stay off however they are ticked here.',
            $current->get('denied_regex')
        ));
    }

    /** @return string the menu key chosen */
    private function menu(ToolExposure $current, ToolExposure $next, bool $dryRun): string
    {
        $this->header($current, $next);

        $changed = $next->diff($current);
        $ticked = count($next->selectedNames());
        $groups = count($next->enabledToolsets());
        $total = count(ToolRegistry::all());

        $options = [
            'groups' => sprintf('%-24s %d of %d groups on', 'Groups', $groups, count($next->toolsets())),
            'commands' => sprintf('%-24s %d of %d commands on', 'Commands', $ticked, $total),
            'ceiling' => sprintf('%-24s %s', 'Ceiling', $this->ceilingLabel($next)),
            'save' => match (true) {
                $dryRun => 'Review it — this is a dry run, so nothing will be written',
                $changed === [] => 'Review and save — nothing has changed yet',
                default => sprintf('Review and save — %d setting(s) changed', count($changed)),
            },
            'back' => $changed === [] ? 'Back' : 'Back, throwing these ticks away',
        ];

        return (string) select(
            label: 'What may every assistant use?',
            options: $options,
            default: $changed === [] ? 'groups' : 'save',
            scroll: count($options),
            hint: sprintf('%d of %d commands would be offered.', count($next->exposed()), $total),
        );
    }

    /** Whole groups, on or off. */
    private function tickGroups(ToolExposure $current, ToolExposure $next): ToolExposure
    {
        return $next->selecting($this->picker($current, $next)->groups(
            $next->selectedNames(),
            'Which groups of commands may the assistant use?',
        ));
    }

    /** One group's commands, one checkbox each. */
    private function tickCommands(ToolExposure $current, ToolExposure $next): ToolExposure
    {
        return $next->selecting($this->picker($current, $next)->commands(
            $next->selectedNames(),
            'Which commands may assistants use?',
        ));
    }

    /**
     * The checkbox screens, told what this section's universe and labels are.
     *
     * The universe is every command the engine has: this section decides what
     * the engine offers at all, so nothing narrows it first.
     */
    private function picker(ToolExposure $current, ToolExposure $next): CommandPicker
    {
        return new CommandPicker(
            groups: $this->groups($next),
            header: fn () => $this->header($current, $next),
            note: fn (string $name): string => $this->commandNote($name, $next),
            groupNote: fn (string $toolset): string => $this->groupNote($toolset, $next),
        );
    }

    /**
     * The ceiling. Not a tick: it is what a ticked command is allowed to *do*,
     * and it cannot be talked around — ticking a destructive command does not
     * get it past a readonly ceiling.
     */
    private function askCeiling(ToolExposure $current, ToolExposure $next): string
    {
        $this->header($current, $next);

        $options = [];

        foreach ([
            ToolPolicy::MODE_READONLY => 'Read only — the assistant can look, and change nothing',
            ToolPolicy::MODE_MODIFY => 'Read, create and update — no DELETE routes (still runs ssh_run, wp_cli_run, backups and file writes)',
            ToolPolicy::MODE_FULL => 'Everything, including deletions',
        ] as $mode => $description) {
            $options[$mode] = sprintf(
                '%-8s %s (%d of the ticked commands)',
                $mode,
                $description,
                count($next->with(['permission_mode' => $mode])->exposed())
            );
        }

        return (string) select(
            label: 'What may the assistant do with the commands you have ticked?',
            options: $options,
            default: $next->policy()->mode(),
            scroll: 3,
            hint: 'A ceiling, not a default. No tick can raise it.',
        );
    }

    /**
     * Every group and the commands in it, by name.
     *
     * @return array<string, array<int, string>>
     */
    private function groups(ToolExposure $next): array
    {
        $policy = $next->policy();
        $groups = [];

        foreach (ToolRegistry::byToolset($policy) as $toolset => $classes) {
            $groups[$toolset] = array_map(fn (string $c): string => $policy->nameOf($c), $classes);
        }

        return $groups;
    }

    /** How many of a ticked group's commands the ceiling then keeps back. */
    private function groupNote(string $toolset, ToolExposure $next): string
    {
        $names = $this->groups($next)[$toolset] ?? [];
        $selected = array_flip($next->selectedNames());
        $exposed = array_flip($next->exposedNames());

        $held = array_filter(
            $names,
            fn (string $n): bool => isset($selected[$n]) && !isset($exposed[$n])
        );

        return $held === [] ? '' : sprintf('(%d above the ceiling)', count($held));
    }

    /**
     * What a command does to the server, and whether the ceiling allows it:
     * ticking a command above the ceiling changes nothing until it is raised.
     */
    private function commandNote(string $name, ToolExposure $next): string
    {
        $policy = $next->policy();

        foreach (ToolRegistry::all() as $class) {
            if ($policy->nameOf($class) !== $name) {
                continue;
            }

            return $policy->accessOf($class) . ($policy->permits($class) ? '' : '  (above the ceiling)');
        }

        return '';
    }

    private function ceilingLabel(ToolExposure $next): string
    {
        return match ($next->policy()->mode()) {
            ToolPolicy::MODE_READONLY => 'readonly — they may look, and change nothing',
            ToolPolicy::MODE_MODIFY => 'modify — they may create and update, but no DELETE routes (ssh_run, wp_cli_run, backups and file writes still run)',
            default => 'full — they may do anything, including delete',
        };
    }

    /**
     * The whole result, then one confirmation, then the write.
     *
     * @return int|null an exit code when the section is finished, or null to
     *                  go back to the menu with the ticks still in hand
     */
    private function review(ToolExposure $current, ToolExposure $next, bool $dryRun): ?int
    {
        $this->header($current, $next);
        $this->summarise($current, $next);

        $env = EnvFile::current();

        if ($dryRun) {
            return $this->finishDryRun($next);
        }

        if ($next->diff($current) === []) {
            note('Nothing to save: this is what the engine is already serving.', 'warning');
            pause('Press enter to carry on...');

            return null;
        }

        if (!confirm(label: sprintf('Write this to %s?', $env->path()), default: true)) {
            note('Nothing was written. Your ticks are still here.', 'warning');
            pause('Press enter to carry on...');

            return null;
        }

        return $this->write($env, $current, $next);
    }

    private function finishDryRun(ToolExposure $next): int
    {
        note('Nothing was written: this was a dry run. Drop --dry-run to apply it.', 'warning');

        $this->receipt = array_merge(
            ['A dry run of ' . self::key() . ' would have written:'],
            explode("\n", $this->envBlock($next)),
        );

        pause('Press enter to carry on...');

        return 0;
    }

    /**
     * Write the ticks to the env file and leave the operator a receipt; the
     * final pause lets it be read before the next screen draws over it.
     */
    private function write(EnvFile $env, ToolExposure $current, ToolExposure $next): int
    {
        try {
            $changed = $env->set($next->envLines());
        } catch (Throwable $e) {
            error($e->getMessage());

            return 1;
        }

        $this->adoptInThisProcess($next);

        if ($changed === []) {
            $this->receipt = [sprintf('%s already said that; nothing to do.', $env->path())];
            note($this->receipt[0], 'info');
            pause('Press enter to carry on...');

            return 0;
        }

        $this->receipt = $this->receiptFor($env, $current, $next, $changed);

        note(implode("\n", $this->receipt), 'info');

        $this->sayWhatHappensNext();

        pause('Press enter to carry on...');

        return 0;
    }

    /**
     * Put the new values into this process's config, which booted with the old
     * ones and is what `ToolExposure::current()` reads when the operator comes
     * back to this section. Needed even when the file already agreed.
     */
    private function adoptInThisProcess(ToolExposure $next): void
    {
        foreach (array_keys(ToolExposure::ENV_KEYS) as $key) {
            config(['mcp-tools.' . $key => $next->get($key)]);
        }
    }

    /** The table of what moves, and what the file would end up saying. */
    private function summarise(ToolExposure $current, ToolExposure $next): void
    {
        [$rows, $unchanged] = $this->movedGroups($current, $next);

        if ($rows !== []) {
            table(['Group', 'Offered now', 'After', 'Total'], $rows);
        }

        note(sprintf(
            "%d of %d commands would be offered, against %d now.%s\n\n%s",
            count($next->exposed()),
            count(ToolRegistry::all()),
            count($current->exposed()),
            $unchanged === 0 ? '' : sprintf(' %d group(s) are unaffected.', $unchanged),
            $this->envBlock($next),
        ));

        if (count($next->exposed()) === 0) {
            warning('The assistant would see no commands at all, and could do nothing with this engine.');
        }
    }

    /**
     * Only the groups whose offered count moves, so one ticked command is not
     * lost among thirty-seven unchanged lines; the rest are only counted.
     *
     * @return array{0: list<array{0: string, 1: string, 2: string, 3: string}>, 1: int} rows, unchanged groups
     */
    private function movedGroups(ToolExposure $current, ToolExposure $next): array
    {
        $before = $current->toolsets();
        $rows = [];
        $unchanged = 0;

        foreach ($next->toolsets() as $toolset => $count) {
            $was = $before[$toolset]['exposed'] ?? 0;
            if ($was === $count['exposed']) {
                $unchanged++;

                continue;
            }

            $rows[] = [
                $toolset,
                (string) $was,
                $count['exposed'] === 0 ? 'off' : (string) $count['exposed'],
                (string) $count['total'],
            ];
        }

        return [$rows, $unchanged];
    }

    /**
     * What the operator leaves with, said once more on the way out. The old
     * value is in full so it can be put back; the new one is shortened. The
     * backup sits inside the core container, not beside .env-core on the host.
     *
     * @param  array<int, string> $changed
     * @return array<int, string>
     */
    private function receiptFor(EnvFile $env, ToolExposure $current, ToolExposure $next, array $changed): array
    {
        $was = $current->envLines();
        $now = $next->envLines();

        $moved = array_map(
            fn (string $key): string => sprintf(
                '  %-24s %s → %s',
                $key,
                $was[$key] ?: '(empty)',
                Str::limit($now[$key] ?: '(empty)', 60)
            ),
            $changed
        );

        return array_merge(
            [sprintf(
                'Updated %s%s:',
                $env->path(),
                $env->isCoreMount() ? ' — the file you know as .env-core' : '',
            )],
            $moved,
            [sprintf(
                'The file as it was is at %s%s.',
                $env->path() . EnvFile::BACKUP_SUFFIX,
                $env->isCoreMount() ? ', inside the core container rather than next to .env-core on the host' : '',
            )],
        );
    }

    /** The lines about to be written, as they will read in the file. */
    private function envBlock(ToolExposure $next): string
    {
        $lines = [];

        foreach ($next->envLines() as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return implode("\n", $lines);
    }

    /** A cached config was built from the old values and would ignore the file just written. */
    private function sayWhatHappensNext(): void
    {
        if (app()->configurationIsCached()) {
            note('The cached configuration was built from the old values — run `pae config:cache` to rebuild it.', 'warning');
        }

        note(
            "The engine reads this on its next request, so `pae mcp:tool:list` will show the new set now.\n"
            . "A connected client will not: it asked for the command list once, when it connected.\n"
            . 'Reconnect it. If it still sees the old list, restart the engine.',
            'warning'
        );
    }

    /** Leaving with ticks in hand is a thing to be asked about once. */
    private function mayLeave(ToolExposure $current, ToolExposure $next): bool
    {
        if ($next->diff($current) === []) {
            return true;
        }

        return confirm(
            label: 'Leave without saving? The ticks you made will be thrown away.',
            default: false,
        );
    }
}
