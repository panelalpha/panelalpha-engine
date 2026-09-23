<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\EngineArtifactExclude;
use App\System\Project\Dind\Source\EngineArtifactMigration;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * ADR-0001, ticket 04 (W9): move an account deployed by the previous engine
 * onto the reserved run-file layout, once, without restarting anything.
 *
 * Run by the updater before `project:rebuild --all`, so the rebuild already
 * sees the new layout — see AGENTS.md and docs/internal/plans for why this
 * has to run first.
 */
class MigrateEngineArtifacts extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:migrate-engine-artifacts', 'users:migrate-engine-artifacts'];

    protected $signature = 'project:migrate-engine-artifacts {username?} {--all}';

    protected $description = "Move a DinD account's compose/composer files onto the engine's reserved names (ADR-0001)";

    public function handle(): int
    {
        /** @var ?string */
        $username = $this->argument('username');
        /** @var bool */
        $all = (bool) $this->option('all');

        if (!$username && !$all) {
            $this->error('One of the following is required: a username, or `--all`.');

            return 1;
        }

        if ($username) {
            $user = User::findByUsername($username);
            if (!$user) {
                $this->error('Invalid username');

                return 1;
            }

            return $this->migrateUsers([$user]);
        }

        return $this->migrateUsers(User::all());
    }

    /**
     * @param array<User>|Collection<int, User> $users
     */
    private function migrateUsers($users): int
    {
        $ok = true;
        foreach ($users as $user) {
            $runtime = $user->project()->runtime();
            if (!$runtime instanceof Dind) {
                continue;
            }

            $this->output->write("Migrating '{$user->username}'...\n");
            try {
                $report = EngineArtifactMigration::forProject($runtime)->migrate();
                EngineArtifactExclude::forProject($runtime)->write();

                if ($report === []) {
                    $this->info('  Nothing to do — already on the current layout.');
                } else {
                    foreach ($report as $line) {
                        $this->info("  {$line}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("  {$e->getMessage()}");
                $ok = false;
            }
        }

        return (int) !$ok;
    }
}
