<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * `--project=NAME` or `--all`: the preamble every fleet-wide command starts
 * with. It was copied into eleven of them, together with both of its error
 * messages and the option fragment itself.
 */
trait SelectsProjects
{
    use ResolvesProject;

    /**
     * The projects this invocation names, or null when it names none -- in
     * which case the reason has already been printed and the command should
     * return 1.
     *
     * @return array<User>|Collection<int, User>|null
     */
    protected function selectedProjects(): array|Collection|null
    {
        if (!$this->namesProjects()) {
            return null;
        }

        $username = $this->option('username');
        if ($username) {
            $user = User::findByUsername((string) $username);
            if (!$user) {
                $this->error('Invalid username');

                return null;
            }

            return [$user];
        }

        return User::all();
    }

    /**
     * Whether the invocation names a project or --all, saying so when it does
     * not. Asked on its own by a command that validates its other options
     * before it looks the project up.
     */
    protected function namesProjects(): bool
    {
        $this->foldProjectOption();

        if (!$this->option('username') && !$this->option('all')) {
            $this->error('One of following options is required: `--project=NAME` or `--all`');

            return false;
        }

        return true;
    }
}
