<?php

namespace App\Console\Commands\Projects;

use App\Models\User;
use App\System;
use App\System\Projects;
use Illuminate\Console\Command;

class ProjectPushCommand extends Command
{
    protected $aliases = ['users:push'];

    protected $signature = 'project:push {project : Source username} {target : Destination username}';

    protected $description = 'Push project state from source to paired target (synchronous)';

    public function handle(): int
    {
        $fromUsername = (string) $this->argument('project');
        $toUsername = (string) $this->argument('target');

        $from = User::findByUsername($fromUsername);
        if (! $from) {
            $this->error("No such user: '{$fromUsername}'");

            return 1;
        }

        $to = User::findByUsername($toUsername);
        if (! $to) {
            $this->error("No such user: '{$toUsername}'");

            return 1;
        }

        try {
            Projects::assertCanPush($from, $to);

            $to->mergeAsyncStatus([
                'push' => 'running',
                'source' => 'artisan',
            ]);
            $to->setDetails(['error' => null]);
            $to->save();

            (new System)->projects()->pushToApp($fromUsername, $toUsername);

            $this->info("Push completed: {$fromUsername} -> {$toUsername}");

            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
