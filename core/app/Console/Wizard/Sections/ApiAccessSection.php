<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Project\ApiAccess;
use App\Models\User;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Which projects may call this engine's API, and with what scope. An allowed
 * project's containers reach the API port, and its environment carries the API
 * address, a token limited to the scope, and the certificate where a client
 * needs one. `pae system:api-access:*` does the same without a menu.
 */
class ApiAccessSection implements Section
{
    use KeepsAReceipt;

    public static function key(): string
    {
        return 'api-access';
    }

    public static function label(): string
    {
        return 'Engine API access — projects that may call this engine';
    }

    public static function hint(): string
    {
        return 'Each allowed project gets a scoped token, a firewall rule and the API address in its environment.';
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $entries = $this->access()->all();
            $this->header($entries);

            switch ((string) select(
                label: 'What would you like to do?',
                options: $this->actions($entries, $dryRun),
                default: 'allow',
            )) {
                case 'allow':
                    $this->allow($dryRun);
                    break;

                case 'rotate':
                    $this->rotate($entries, $dryRun);
                    break;

                case 'deny':
                    $this->deny($entries, $dryRun);
                    break;

                default:
                    return 0;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function header(array $entries): void
    {
        $lines = ['Allowed:     '.($entries === [] ? 'none' : count($entries).' project(s)')];
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '             %-16s scope %-16s %s',
                (string) $entry['project'],
                ApiAccess::scopeLabel($entry['scope'] ?? ApiAccess::SCOPE_SELF),
                ($entry['mcp'] ?? false) ? 'with MCP tools' : 'REST only',
            );
        }

        Screen::draw(self::label(), implode("\n", $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, string>
     */
    private function actions(array $entries, bool $dryRun): array
    {
        $options = ['allow' => 'Allow a project — mint a token and open the port'];
        if ($entries !== []) {
            $options['rotate'] = 'Rotate a token — replace it without changing the scope';
            $options['deny'] = 'Deny a project — revoke its token and close the port';
        }
        $options['back'] = $dryRun ? 'Back — nothing has been written' : 'Back';

        return $options;
    }

    private function allow(bool $dryRun): void
    {
        Screen::draw(self::label().'  ·  Allow');
        note('The project\'s containers may then reach this engine\'s API port, and its environment carries the '
            .'API address, a token with the scope you pick, and the certificate it is served.');

        $username = $this->askProject();
        $scope = $username === null ? null : $this->askScope();

        if ($username === null || $scope === null) {
            return;
        }

        $mcp = confirm('Let this token call MCP tools as well?', default: false);
        $summary = sprintf(
            'Allow %s to use the Engine API, scope %s%s.',
            $username,
            ApiAccess::scopeLabel($scope),
            $mcp ? ', including MCP tools' : ''
        );

        if (! $this->confirmWrite($summary, $dryRun)) {
            return;
        }

        $user = User::findByUsername($username);
        if ($user === null) {
            return;
        }

        $this->write($summary, fn () => $this->access()->allow($user, $scope, $mcp));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function rotate(array $entries, bool $dryRun): void
    {
        Screen::draw(self::label().'  ·  Rotate');
        $username = $this->pick($entries);
        $user = $username === null ? null : User::findByUsername($username);

        if ($user === null) {
            return;
        }

        $summary = "Mint a new token for {$username}, keeping its scope.";
        if (! $this->confirmWrite($summary, $dryRun)) {
            return;
        }

        $this->write($summary, fn () => $this->access()->rotate($user));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function deny(array $entries, bool $dryRun): void
    {
        Screen::draw(self::label().'  ·  Deny');
        $username = $this->pick($entries);
        if ($username === null) {
            return;
        }

        $summary = "Deny {$username} the Engine API: its token is revoked and the port is closed.";
        if (! $this->confirmWrite($summary, $dryRun)) {
            return;
        }

        $this->write($summary, fn () => $this->access()->deny($username));
    }

    private function askProject(): ?string
    {
        $value = trim(text(
            label: 'Project username',
            required: true,
            validate: fn (string $name): ?string => User::findByUsername(trim($name)) === null
                ? 'There is no project by that name on this engine.'
                : null,
        ));

        return $value === '' ? null : $value;
    }

    /**
     * @return string|list<string>|null
     */
    private function askScope(): string|array|null
    {
        $choice = (string) select(
            label: 'Which projects may its token act on?',
            options: [
                'self' => 'Only itself',
                ApiAccess::SCOPE_ALL => 'Every project on this engine',
                'list' => 'A list of projects',
            ],
            default: 'self',
        );

        if ($choice !== 'list') {
            return $choice;
        }

        $value = trim(text(
            label: 'Project usernames, comma separated',
            required: true,
            validate: fn (string $names): ?string => $this->unknownProject($names),
        ));

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $name): bool => $name !== ''
        ));
    }

    private function unknownProject(string $names): ?string
    {
        foreach (array_map('trim', explode(',', $names)) as $name) {
            if ($name !== '' && User::findByUsername($name) === null) {
                return "There is no project called '{$name}' on this engine.";
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function pick(array $entries): ?string
    {
        if ($entries === []) {
            note('No project is allowed to use the Engine API.', 'warning');
            pause('Press enter to carry on...');

            return null;
        }

        $options = [];
        foreach ($entries as $entry) {
            $options[(string) $entry['project']] = sprintf(
                '%-16s %s',
                (string) $entry['project'],
                ApiAccess::scopeLabel($entry['scope'] ?? ApiAccess::SCOPE_SELF),
            );
        }

        return (string) select(label: 'Which project?', options: $options, scroll: count($options));
    }

    private function confirmWrite(string $summary, bool $dryRun): bool
    {
        if ($dryRun) {
            note($summary."\n\nNothing was written: this was a dry run.");
            $this->receipt[] = $summary;
            pause('Press enter to carry on...');

            return false;
        }

        if (confirm(label: $summary, default: true)) {
            return true;
        }

        note('Nothing was written.', 'warning');
        pause('Press enter to carry on...');

        return false;
    }

    private function write(string $summary, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            note($e->getMessage(), 'error');
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = $summary;
        note($summary, 'info');
        note('Takes effect now for a running project: the environment is rewritten and the stack restarted '
            .'without a rebuild. The task list shows the apply.');
        pause('Press enter to carry on...');
    }

    private function access(): ApiAccess
    {
        return new ApiAccess;
    }
}
