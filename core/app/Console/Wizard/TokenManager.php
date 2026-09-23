<?php

namespace App\Console\Wizard;

use App\Auth\TokenAbilities;
use App\Console\Prompts\Screen;
use App\Console\Wizard\Scopes\Scope;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The tokens of one kind: listing them, minting one, narrowing it, revoking
 * and deleting.
 *
 * One class for both kinds, because the lifecycle is identical and only the
 * words differ — which matters more than saving the duplication. A section
 * that manages assistants should never mention the API and vice versa: an
 * operator who came to mint a token for their deploy script is not being asked
 * to have an opinion about assistants, and a menu that asks anyway is a menu
 * that gets answered wrong. So the kind is fixed when this is constructed and
 * nothing below offers the other one.
 */
class TokenManager
{
    /** @var array<int, string> */
    private array $receipt = [];

    /**
     * @param string $ability  {@see TokenAbilities::API} or {@see TokenAbilities::MCP}
     * @param string $noun     what a token of this kind is for, e.g. 'assistant'
     * @param string $narrow   the menu row that opens the scope editor
     * @param string $mintHint what to say once a token has been minted
     */
    public function __construct(
        private readonly string $title,
        private readonly bool $dryRun,
        private readonly string $ability,
        private readonly Scope $scope,
        private readonly string $noun,
        private readonly string $narrow,
        private readonly string $mintHint = '',
    ) {
    }

    /** @return array<int, string> */
    public function receipt(): array
    {
        return $this->receipt;
    }

    public function run(): int
    {
        while (true) {
            try {
                $tokens = $this->tokens();
            } catch (Throwable $e) {
                // Tokens live in the database, unlike everything else the
                // wizard touches. Stack-tracing here would look broken rather
                // than unable to reach the one thing it needs.
                Screen::draw($this->title);
                error('Could not read the tokens: ' . $e->getMessage());
                note('This needs the engine database.');
                pause('Press enter to carry on...');

                return 1;
            }

            $this->header($tokens);

            $options = [];

            foreach ($tokens as $token) {
                $options[(string) $token->id] = $this->row($token);
            }

            $options['new'] = sprintf('Mint a new token for %s…', $this->noun);
            $options['back'] = 'Back';

            $chosen = (string) select(
                label: 'Which token?',
                options: $options,
                scroll: min(16, count($options)),
                hint: 'Arrow keys to choose, Enter to open it',
            );

            if ($chosen === 'back') {
                return 0;
            }

            if ($chosen === 'new') {
                $this->mint();

                continue;
            }

            foreach ($tokens as $token) {
                if ((string) $token->id === $chosen) {
                    $this->inspect($token);

                    break;
                }
            }
        }
    }

    /** One token: what it is, and the few things that can be done to it. */
    private function inspect(PersonalAccessToken $token): void
    {
        while (true) {
            $this->tokenHeader($token);

            switch ((string) select(
                label: sprintf('Token %d, "%s"', $token->id, $token->name),
                options: [
                    'scope' => $this->narrow,
                    'revoke' => $token->isRevoked() ? 'Already revoked' : 'Revoke it',
                    'delete' => 'Delete it',
                    'back' => 'Back',
                ],
                default: 'scope',
                scroll: 4,
            )) {
                case 'scope':
                    $editor = new TokenScopeEditor($this->title, $this->dryRun, $this->scope);
                    $editor->edit($token);
                    $this->receipt = array_merge($this->receipt, $editor->receipt());
                    $token->refresh();
                    break;

                case 'revoke':
                    if ($this->revoke($token)) {
                        return;
                    }

                    break;

                case 'delete':
                    if ($this->delete($token)) {
                        return;
                    }

                    break;

                default:
                    return;
            }
        }
    }

    /** @return bool whether this token is finished with */
    private function revoke(PersonalAccessToken $token): bool
    {
        if ($token->isRevoked()) {
            note(sprintf('Token %d was revoked on %s already.', $token->id, $token->revoked_at), 'warning');
            pause('Press enter to carry on...');

            return false;
        }

        $this->tokenHeader($token);
        note(
            "Revoking stops this token working, at once and for good.\n"
            . 'The row stays, so the audit trail keeps its name. Deleting removes it entirely.'
        );

        if ($this->dryRun) {
            note('Nothing was written: this was a dry run.', 'warning');
            pause('Press enter to carry on...');

            return false;
        }

        if (!confirm(label: sprintf('Revoke token %d, "%s"?', $token->id, $token->name), default: false)) {
            return false;
        }

        try {
            $token->forceFill(['revoked_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return false;
        }

        $this->receipt[] = sprintf('Revoked token %d ("%s"). It no longer works.', $token->id, $token->name);
        note(end($this->receipt), 'info');
        pause('Press enter to carry on...');

        return true;
    }

    /** @return bool whether this token is finished with */
    private function delete(PersonalAccessToken $token): bool
    {
        $this->tokenHeader($token);
        warning('Deleting removes the row. Whatever is using this token stops working, with no record of what it was.');
        note('Revoking is usually what you want: the token stops working and the audit trail keeps its name.');

        if ($this->dryRun) {
            note('Nothing was written: this was a dry run.', 'warning');
            pause('Press enter to carry on...');

            return false;
        }

        if (!confirm(label: sprintf('Delete token %d, "%s"?', $token->id, $token->name), default: false)) {
            return false;
        }

        $name = (string) $token->name;
        $id = $token->id;

        try {
            $token->delete();
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return false;
        }

        $this->receipt[] = sprintf('Deleted token %d ("%s").', $id, $name);
        note(end($this->receipt), 'info');
        pause('Press enter to carry on...');

        return true;
    }

    /**
     * Mint one.
     *
     * The plaintext is shown once and never again, so it goes in the receipt
     * as well as on screen: every screen here is drawn over the last, and a
     * secret that scrolled away with the menu would be a token nobody has.
     */
    private function mint(): void
    {
        Screen::draw($this->title . '  ·  New token');

        $name = trim(text(
            label: sprintf('What is this token for?'),
            placeholder: 'deploy-bot, billing-sync',
            hint: 'A name you will recognise in the list and in the audit log.',
        ));

        if ($name === '') {
            return;
        }

        Screen::draw($this->title . '  ·  New token');

        $expires = trim(text(
            label: 'How long should it last?',
            placeholder: '90d',
            hint: 'A number and a unit — 30m, 12h, 90d, 2w. Leave empty for a token that never expires.',
            validate: fn (string $v): ?string => trim($v) === '' || preg_match('/^\d+\s*[smhdw]$/i', trim($v)) === 1
                ? null
                : 'Give a number and a unit, like 90d — or leave it empty.',
        ));

        if ($this->dryRun) {
            note('Nothing was written: this was a dry run.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            $token = Admin::rootAccount()->createToken(
                $name,
                TokenAbilities::build(
                    api: $this->ability === TokenAbilities::API,
                    mcp: $this->ability === TokenAbilities::MCP,
                ),
                $expires === '' ? null : $this->moment($expires),
            );
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt = array_merge($this->receipt, [
            sprintf(
                'Minted token %d, "%s" for %s — %s.',
                $token->accessToken->id,
                $name,
                $this->noun,
                $expires === '' ? 'never expires' : 'expires in ' . $expires,
            ),
            'It is shown once and cannot be retrieved again:',
            '  ' . $token->plainTextToken,
        ]);

        Screen::draw($this->title . '  ·  New token');
        note(implode("\n", array_slice($this->receipt, -3)), 'info');
        warning('Copy it now. This is the only time it is shown.');

        if ($this->mintHint !== '') {
            note($this->mintHint);
        }

        pause('Press enter when you have it...');
    }

    /** @param array<int, PersonalAccessToken> $tokens */
    private function header(array $tokens): void
    {
        $limited = 0;

        foreach ($tokens as $token) {
            if ($this->scope->current(TokenAbilities::of($token)) !== null) {
                $limited++;
            }
        }

        Screen::draw($this->title, $tokens === []
            ? sprintf('There are no tokens for %s yet.', $this->noun)
            : sprintf(
                '%d token(s) for %s, %s.',
                count($tokens),
                $this->noun,
                $limited === 0 ? 'none limited' : sprintf('%d limited', $limited)
            ));
    }

    private function tokenHeader(PersonalAccessToken $token): void
    {
        $abilities = TokenAbilities::of($token);
        $keys = $this->scope->current($abilities);
        [, $many] = $this->scope->noun();
        $offered = array_merge(...array_values($this->scope->universe()));

        $lines = [
            sprintf('May call:  %s', $keys === null
                ? sprintf('every %s this engine has', rtrim($many, 's'))
                : sprintf('%d of %d %s', count(array_intersect($keys, $offered)), count($offered), $many)),
            sprintf('Expires:   %s', $token->expires_at ?? 'never'),
            sprintf('Last used: %s', $token->last_used_at ?? 'never'),
        ];

        if ($token->isRevoked()) {
            $lines[] = sprintf('Revoked:   %s', $token->revoked_at);
        }

        Screen::draw($this->title, implode("\n", $lines));

        if ($abilities->isLegacy()) {
            warning('This token predates the split into kinds and is not limited to one. Minting a new one is the way to narrow it.');
        }
    }

    private function row(PersonalAccessToken $token): string
    {
        $abilities = TokenAbilities::of($token);
        $keys = $this->scope->current($abilities);
        [, $many] = $this->scope->noun();

        return sprintf(
            '%-22s %-20s %s',
            substr((string) $token->name, 0, 22),
            $keys === null ? 'unlimited' : sprintf('%d %s', count($keys), $many),
            $token->isRevoked()
                ? 'revoked'
                : ($token->expires_at === null ? '' : 'expires ' . $token->expires_at->format('Y-m-d')),
        );
    }

    private function moment(string $expires): Carbon
    {
        preg_match('/^(\d+)\s*([smhdw])$/i', $expires, $m);

        return Carbon::now()->add(match (strtolower($m[2])) {
            's' => 'seconds',
            'm' => 'minutes',
            'h' => 'hours',
            'w' => 'weeks',
            default => 'days',
        }, (int) $m[1]);
    }

    /**
     * The tokens of this kind.
     *
     * A token from before the split carries `*` and satisfies both, so it
     * appears in both sections. That is the truth about it, and hiding it from
     * one would hide the broadest credential on the engine from half the
     * places someone might go looking to narrow it.
     *
     * @return array<int, PersonalAccessToken>
     */
    private function tokens(): array
    {
        return Admin::rootAccount()
            ->tokens()
            ->orderBy('id')
            ->get()
            ->filter(fn (PersonalAccessToken $t): bool => $this->ability === TokenAbilities::API
                ? TokenAbilities::of($t)->mayUseApi()
                : TokenAbilities::of($t)->mayUseMcp())
            ->values()
            ->all();
    }
}
