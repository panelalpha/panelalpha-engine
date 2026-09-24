<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Support\DockerHubLogin;
use App\System;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The Docker Hub login every Hub pull on this engine goes out under, via
 * registry-proxy. `pae docker-hub:login` does the same without a menu.
 */
class DockerHubSection implements Section
{
    use KeepsAReceipt;

    private DockerHubLogin $login;

    public function __construct()
    {
        $this->login = new DockerHubLogin(new System());
    }

    public static function key(): string
    {
        return 'docker-hub';
    }

    public static function label(): string
    {
        return 'Docker Hub — the login image pulls go out under';
    }

    public static function hint(): string
    {
        return 'Without one, every pull shares the host IP\'s anonymous rate limit.';
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $current = $this->login->current();
            $this->header($current);

            $options = ['login' => ($current['username'] ?? '') === '' ? 'Set a login' : 'Replace the login'];
            if (($current['username'] ?? '') !== '') {
                $options['logout'] = 'Remove it — pull anonymously';
            }
            $options['check'] = 'Check it again';
            $options['back'] = 'Back';

            switch ((string) select(label: 'What would you like to do?', options: $options, default: 'login')) {
                case 'login':
                    $this->setLogin($dryRun);
                    break;

                case 'logout':
                    $this->clearLogin($dryRun);
                    break;

                case 'check':
                    break;

                default:
                    return 0;
            }
        }
    }

    /**
     * @param array{username: string, token: string}|null $current
     */
    private function header(?array $current): void
    {
        $lines = [];
        if ($current === null) {
            $lines[] = 'registry-proxy: not running — accounts pull straight from Docker Hub';
        } elseif ($current['username'] === '') {
            $lines[] = 'Login:       none — the host IP\'s anonymous limit';
        } else {
            $check = $this->login->check($current['username'], $current['token']);
            $lines[] = "Login:       {$current['username']}";
            $lines[] = 'Rate limit:  ' . ($check['ok']
                ? ($check['remaining'] ?? '?') . ' left of ' . ($check['limit'] ?? '?')
                : (string) $check['error']);
        }
        $lines[] = 'Host daemon: ' . match ($this->login->hostMirrors()) {
            true => 'pulls through registry-proxy too',
            false => 'pulls Docker Hub directly',
            default => 'could not be asked',
        };

        Screen::draw(self::label(), implode("\n", $lines));
    }

    private function setLogin(bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  Login');
        note("Create a personal access token on Docker Hub with the Public Repo Read-only scope.\n"
            . 'registry-proxy serves whatever this login can read to every account on the engine.');

        $username = trim(text('Docker Hub username', validate: fn (string $v) => DockerHubLogin::badUsername(trim($v))));
        $token = trim(password('Access token', validate: fn (string $v) => DockerHubLogin::badToken(trim($v))));

        $check = $this->login->check($username, $token);
        if (!$check['ok']) {
            warning((string) $check['error']);
            pause('Press enter to carry on...');

            return;
        }
        note("Docker Hub accepts it: {$username}" . ($check['limit'] !== null ? ", {$check['limit']}" : ''), 'info');

        $private = $this->login->pullablePrivateRepositories($username, $token);
        if ($private === null) {
            warning('Could not check whether this token reaches private repositories.');
        } elseif ($private !== []) {
            warning("This token can pull private repositories, which every account could then pull:\n  "
                . implode("\n  ", $private));
            if (!confirm('Save it anyway?', default: false)) {
                return;
            }
        }

        if ($dryRun) {
            note("Dry run: REGISTRY_PROXY_USERNAME would become {$username}, and registry-proxy would be recreated.", 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            $this->login->save($username, $token);
        } catch (Throwable $e) {
            warning('Could not save it: ' . trim($e->getMessage()));
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = "registry-proxy pulls from Docker Hub as {$username}.";
        note(end($this->receipt), 'info');
        pause('Press enter to carry on...');
    }

    private function clearLogin(bool $dryRun): void
    {
        if (!confirm('Pull from Docker Hub anonymously again?', default: false)) {
            return;
        }
        if ($dryRun) {
            note('Dry run: the login would be removed and registry-proxy recreated.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            $this->login->clear();
        } catch (Throwable $e) {
            warning('Could not remove it: ' . trim($e->getMessage()));
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = 'registry-proxy pulls from Docker Hub anonymously.';
        note(end($this->receipt), 'info');
        pause('Press enter to carry on...');
    }
}
