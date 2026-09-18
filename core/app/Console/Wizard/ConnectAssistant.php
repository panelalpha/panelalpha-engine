<?php

namespace App\Console\Wizard;

use App\Auth\TokenAbilities;
use App\Console\Prompts\Screen;
use App\Mcp\ClientRegistration;
use App\Models\Admin;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * `pae connect`, inside the wizard: pick an assistant, mint it a token, and
 * print the command that connects it.
 *
 * The same three steps as the standalone command, in the place someone already
 * is when they think to do it. Minting a token and then working out what to do
 * with it are one errand, not two, and the command that carries the token is
 * the only useful form of it — a bare token still leaves an operator to
 * translate one client's setup into another's.
 *
 * What it prints goes into the receipt as well as on screen. Every screen here
 * is drawn over the last, and this is the one output in the whole wizard that
 * cannot be asked for again: the token appears once, inside the command.
 */
class ConnectAssistant
{
    /** @var array<int, string> */
    private array $receipt = [];

    public function __construct(
        private readonly string $title,
        private readonly bool $dryRun,
    ) {
    }

    /** @return array<int, string> */
    public function receipt(): array
    {
        return $this->receipt;
    }

    public function run(): void
    {
        $labels = ClientRegistration::labels();

        Screen::draw($this->title . '  ·  Connect an assistant');

        $this->checkAddress();

        $client = (string) select(
            label: 'Which assistant are you connecting?',
            options: $labels + ['back' => 'Back'],
            default: array_key_first($labels),
            scroll: min(16, count($labels) + 1),
            hint: 'Arrow keys to choose. Using something else? Any assistant that speaks MCP will work.',
        );

        if (!isset($labels[$client])) {
            return;
        }

        Screen::draw($this->title . '  ·  Connect an assistant');

        $name = trim(text(
            label: 'What should this token be called?',
            default: $client,
            hint: 'How it will appear in the token list and in the audit log. One per assistant is easiest to revoke.',
        ));

        if ($name === '') {
            return;
        }

        $expires = trim(text(
            label: 'How long should it last?',
            placeholder: '90d',
            hint: 'A number and a unit — 30d, 90d, 1w. Leave empty for a token that never expires.',
            validate: fn (string $v): ?string => trim($v) === '' || preg_match('/^\d+\s*[smhdw]$/i', trim($v)) === 1
                ? null
                : 'Give a number and a unit, like 90d — or leave it empty.',
        ));

        if ($this->dryRun) {
            note('Nothing was written: this was a dry run. No token was minted.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            $token = Admin::rootAccount()->createToken(
                $name,
                TokenAbilities::build(api: false, mcp: true),
                $expires === '' ? null : now()->add($this->unit($expires), $this->amount($expires)),
            );

            $entry = ClientRegistration::for(
                $client,
                rtrim((string) config('app.url'), '/') . '/mcp',
                $token->plainTextToken,
            );
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt = array_merge(
            [
                sprintf('To connect %s, run this on your own computer:', $entry['label']),
                '',
            ],
            array_map(fn (string $line): string => '  ' . $line, $entry['lines']),
            [
                '',
                sprintf(
                    'It carries token %d ("%s"), %s. It is not shown again.',
                    $token->accessToken->id,
                    $name,
                    $expires === '' ? 'which never expires' : 'which expires in ' . $expires,
                ),
            ],
        );

        Screen::draw($this->title . '  ·  Connect an assistant');
        note(implode("\n", $this->receipt), 'info');

        if (isset($entry['note'])) {
            note('What it does: ' . $entry['note']);
        }

        warning('Copy it now. The token inside it is shown only this once.');
        note('The assistant can use every command this engine offers. Tokens is where you narrow that.');

        pause('Press enter when you have it...');
    }

    /**
     * Whether the address this engine thinks it has is one an assistant could
     * reach.
     *
     * The setup command carries `APP_URL` verbatim, so an engine that has not
     * been told its own address hands out a command that cannot work — and it
     * fails on the assistant's machine, minutes later, with nothing pointing
     * back to here. Worth saying before the token is minted rather than after.
     */
    private function checkAddress(): void
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            warning(sprintf(
                "This engine thinks its address is %s, which no assistant can reach.\n"
                . 'Set APP_URL in .env-core to the address and port you connect on, then restart the engine.',
                $url === '' ? '(unset)' : $url
            ));

            return;
        }

        if (str_starts_with($url, 'http://')) {
            warning(sprintf(
                "This engine's address is %s. Most assistants refuse a plain http:// MCP server.",
                $url
            ));
        }
    }

    private function amount(string $expires): int
    {
        preg_match('/^(\d+)/', $expires, $m);

        return (int) $m[1];
    }

    private function unit(string $expires): string
    {
        preg_match('/([smhdw])$/i', trim($expires), $m);

        return match (strtolower($m[1])) {
            's' => 'seconds',
            'm' => 'minutes',
            'h' => 'hours',
            'w' => 'weeks',
            default => 'days',
        };
    }
}
