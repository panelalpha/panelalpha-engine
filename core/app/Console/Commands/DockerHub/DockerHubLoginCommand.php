<?php

namespace App\Console\Commands\DockerHub;

use App\Support\DockerHubLogin;
use App\System;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Give registry-proxy a Docker Hub login, so every Hub pull on this engine
 * counts against that account instead of the host IP's anonymous limit.
 *
 *   pae docker-hub:login                                  asks for both
 *   echo "$TOKEN" | pae docker-hub:login --username=me --token-stdin
 */
class DockerHubLoginCommand extends Command
{
    protected $signature = 'docker-hub:login
        {--username= : Docker Hub username}
        {--token-stdin : Read the access token from standard input}
        {--allow-private : Save even when the token can pull private repositories}';

    protected $description = 'Set the Docker Hub login registry-proxy pulls with';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive() && stream_isatty(STDIN) && !$this->option('token-stdin');
        $username = trim((string) ($this->option('username') ?? ''));
        if ($username === '' && $interactive) {
            $username = trim(text('Docker Hub username', validate: fn (string $v) => DockerHubLogin::badUsername(trim($v))));
        }
        $token = $this->option('token-stdin')
            ? trim((string) stream_get_contents(STDIN))
            : ($interactive ? trim(password(
                'Access token',
                hint: 'Docker Hub → Account settings → Personal access tokens. Scope: Public Repo Read-only.',
                validate: fn (string $v) => DockerHubLogin::badToken(trim($v)),
            )) : '');

        $bad = DockerHubLogin::badUsername($username) ?? DockerHubLogin::badToken($token);
        if ($bad !== null) {
            $this->error($bad);
            $this->line('  echo "$TOKEN" | pae docker-hub:login --username=<name> --token-stdin');

            return self::FAILURE;
        }

        $login = new DockerHubLogin(new System());
        $check = $login->check($username, $token);
        if (!$check['ok']) {
            $this->error((string) $check['error']);

            return self::FAILURE;
        }
        $this->info("Docker Hub accepts it: {$username}" . ($check['limit'] !== null ? ", {$check['limit']}" : ''));

        $private = $login->pullablePrivateRepositories($username, $token);
        if ($private === null) {
            $this->warn('Could not check whether this token reaches private repositories. '
                . 'Every account on this engine can pull whatever it can read; use a Public Repo Read-only token.');
        } elseif ($private !== []) {
            $this->warn('This token can pull private repositories, and registry-proxy would serve them to every account:');
            foreach ($private as $repository) {
                $this->line("  {$repository}");
            }
            $allowed = $this->option('allow-private')
                || ($interactive && confirm('Save it anyway?', default: false));
            if (!$allowed) {
                $this->line('Not saved. Create a token with the Public Repo Read-only scope, or pass --allow-private.');

                return self::FAILURE;
            }
        }

        try {
            $login->save($username, $token);
        } catch (\Exception $e) {
            $this->error('Could not save it: ' . trim($e->getMessage()));

            return self::FAILURE;
        }

        $running = $login->current();
        if (($running['username'] ?? null) !== $username) {
            $this->error('Saved to .env, but registry-proxy is not running with it. Check `docker compose ps registry-proxy`.');

            return self::FAILURE;
        }
        $this->info("registry-proxy now pulls from Docker Hub as {$username}.");
        if ($login->hostMirrors() === false) {
            $this->line('The host daemon does not mirror through it yet; `bash scripts/install-sysbox.sh` sets that up.');
        }

        return self::SUCCESS;
    }
}
