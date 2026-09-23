<?php

namespace App\Console\Wizard;

use App\Auth\TokenAbilities;
use App\Console\Prompts\Screen;
use App\Console\Wizard\Scopes\Scope;
use App\Models\PersonalAccessToken;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * Ticking one token's scope on and off.
 *
 * The screens are the same whichever kind of token this is — a list of groups,
 * a list of what is inside one, a checkbox each. The words and the abilities
 * written are the {@see Scope}'s, and everything about the token the scope
 * does not own is left exactly as it was.
 *
 * Ticks accumulate against what is saved rather than being written as they are
 * made, so **Review and save** is one deliberate step, declining it returns to
 * the menu with the ticks intact, and leaving with unsaved ticks asks first.
 */
class TokenScopeEditor
{
    /** @var array<int, string> */
    private array $receipt = [];

    public function __construct(
        private readonly string $title,
        private readonly bool $dryRun,
        private readonly Scope $scope,
    ) {
    }

    /** @return array<int, string> */
    public function receipt(): array
    {
        return $this->receipt;
    }

    public function edit(PersonalAccessToken $token): void
    {
        $offered = $this->offered();
        [$one, $many] = $this->scope->noun();

        // An unlimited token starts with everything ticked, which is what it
        // can in fact do — not an empty list it would have to rebuild to say
        // the same thing.
        $was = $this->scope->current(TokenAbilities::of($token)) ?? $offered;
        $selected = $was;

        while (true) {
            $changed = $this->changed($was, $selected, $offered);
            $groups = $this->scope->universe();

            $options = [
                'groups' => sprintf('%-24s %d of %d groups on', 'Groups', count($this->groupsOn($selected)), count($groups)),
                'items' => sprintf('%-24s %d of %d %s on', ucfirst($many), count(array_intersect($selected, $offered)), count($offered), $many),
                'save' => match (true) {
                    $this->dryRun => 'Review it — this is a dry run, so nothing will be written',
                    !$changed => 'Review and save — nothing has changed yet',
                    default => 'Review and save',
                },
                'back' => $changed ? 'Back, throwing these ticks away' : 'Back',
            ];

            $this->header($token, $was, $selected, $offered);

            switch ((string) select(
                label: sprintf('What may "%s" do?', $token->name),
                options: $options,
                default: $changed ? 'save' : 'groups',
                scroll: count($options),
                hint: sprintf('%d of %d %s ticked.', count(array_intersect($selected, $offered)), count($offered), $many),
            )) {
                case 'groups':
                    $selected = $this->picker($token, $was, $selected, $offered)->groups(
                        $selected,
                        sprintf('Which groups may "%s" use?', $token->name),
                    );
                    break;

                case 'items':
                    $selected = $this->picker($token, $was, $selected, $offered)->commands(
                        $selected,
                        sprintf('Which %s may "%s" use?', $many, $token->name),
                    );
                    break;

                case 'save':
                    if ($this->save($token, $was, $selected, $offered)) {
                        return;
                    }

                    break;

                default:
                    if (!$changed || confirm(
                        label: 'Leave without saving? The ticks you made will be thrown away.',
                        default: false,
                    )) {
                        return;
                    }
            }
        }
    }

    /**
     * @param array<int, string> $was
     * @param array<int, string> $selected
     * @param array<int, string> $offered
     * @return bool whether this token is finished with
     */
    private function save(PersonalAccessToken $token, array &$was, array $selected, array $offered): bool
    {
        $this->header($token, $was, $selected, $offered);
        [$one, $many] = $this->scope->noun();

        $allowed = array_values(array_intersect($selected, $offered));
        sort($allowed);

        // Everything ticked is written as no limit at all, not as a list of
        // every one there is: the token then follows the engine, and anything
        // added later reaches it the way it reaches a token minted before any
        // of this existed.
        $unlimited = count($allowed) === count($offered);
        $abilities = $this->scope->abilitiesFor($token, $unlimited ? null : $allowed);

        note(sprintf(
            "\"%s\" (token %d) would be able to call %d of the %d %s this engine has.\n\n%s",
            $token->name,
            $token->id,
            count($allowed),
            count($offered),
            $many,
            $unlimited
                ? 'Every box is ticked, so it is stored as no limit at all.'
                : sprintf('Stored on the token as %d abilities.', count($abilities)),
        ));

        if ($allowed === []) {
            warning(sprintf('This token would be able to call nothing. Revoking it is the way to turn one off.'));
        }

        if ($this->dryRun) {
            note('Nothing was written: this was a dry run. Drop --dry-run to apply it.', 'warning');
            $this->receipt[] = sprintf(
                'A dry run would have given "%s" %d of %d %s.',
                $token->name,
                count($allowed),
                count($offered),
                $many
            );
            pause('Press enter to carry on...');

            return true;
        }

        if (!$this->changed($was, $selected, $offered)) {
            note('Nothing to save: that is what this token can already do.', 'warning');
            pause('Press enter to carry on...');

            return false;
        }

        if (!confirm(label: sprintf('Save this against token %d?', $token->id), default: true)) {
            note('Nothing was written. Your ticks are still here.', 'warning');
            pause('Press enter to carry on...');

            return false;
        }

        try {
            $token->forceFill(['abilities' => $abilities])->save();
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return false;
        }

        $was = $unlimited ? $offered : $allowed;

        $this->receipt[] = sprintf(
            'Token %d ("%s") may now call %s.',
            $token->id,
            $token->name,
            $unlimited
                ? sprintf('every %s this engine has', $one)
                : sprintf('%d of the %d %s this engine has', count($allowed), count($offered), $many)
        );

        note(end($this->receipt), 'info');
        pause('Press enter to carry on...');

        return true;
    }

    /**
     * @param array<int, string> $was
     * @param array<int, string> $selected
     * @param array<int, string> $offered
     */
    private function header(PersonalAccessToken $token, array $was, array $selected, array $offered): void
    {
        $abilities = TokenAbilities::of($token);
        [, $many] = $this->scope->noun();

        $lines = [sprintf(
            'Token %d, "%s"%s.',
            $token->id,
            $token->name,
            $token->isRevoked() ? ', revoked' : ''
        )];

        $lines[] = sprintf('May call now: %d of %d %s.', count(array_intersect($was, $offered)), count($offered), $many);

        if ($this->changed($was, $selected, $offered)) {
            $lines[] = sprintf('Your ticks:   %d of %d %s.  (unsaved)', count(array_intersect($selected, $offered)), count($offered), $many);
        }

        Screen::draw($this->title, implode("\n", $lines));

        // Names the token kept while the engine's own list moved underneath it.
        $orphaned = array_values(array_diff($this->scope->current($abilities) ?? [], $offered));

        if ($orphaned !== []) {
            warning(sprintf(
                'It also names %d this engine no longer has: %s. Saving drops them.',
                count($orphaned),
                implode(', ', array_slice($orphaned, 0, 3))
            ));
        }
    }

    /**
     * @param array<int, string> $was
     * @param array<int, string> $selected
     * @param array<int, string> $offered
     */
    private function changed(array $was, array $selected, array $offered): bool
    {
        $a = array_values(array_intersect($was, $offered));
        $b = array_values(array_intersect($selected, $offered));
        sort($a);
        sort($b);

        return $a !== $b;
    }

    /**
     * @param array<int, string> $was
     * @param array<int, string> $selected
     * @param array<int, string> $offered
     */
    private function picker(PersonalAccessToken $token, array $was, array $selected, array $offered): CommandPicker
    {
        return new CommandPicker(
            groups: $this->scope->universe(),
            header: fn () => $this->header($token, $was, $selected, $offered),
            note: fn (string $key): string => $this->scope->note($key),
            width: $this->scope->width(),
        );
    }

    /** @return array<int, string> */
    private function offered(): array
    {
        return array_merge(...array_values($this->scope->universe()));
    }

    /**
     * @param array<int, string> $selected
     * @return array<int, string>
     */
    private function groupsOn(array $selected): array
    {
        $ticked = array_flip($selected);
        $on = [];

        foreach ($this->scope->universe() as $group => $keys) {
            foreach ($keys as $key) {
                if (isset($ticked[$key])) {
                    $on[] = $group;

                    break;
                }
            }
        }

        return $on;
    }
}
