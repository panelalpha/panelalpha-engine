<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\PhpErrorLogConfig;

/**
 * Accounts created before PHP errors reached a project file keep sending them only to the webserver, on PHP 8.4 log a
 * JIT warning into it at every start, and keep a document root's user INI for five minutes. Safe to run again: a
 * project that already has the directives is left as it is. A running handler keeps the ini it started with, so the
 * new lines are read at that handler's next restart.
 */
class PhpConfigRefreshCommand extends ProjectFleetCommand
{
    protected $signature = 'projects:php-config:refresh'.ProjectOptions::SIGNATURE;

    protected $description = 'Add the PHP directives existing hosting projects lack; each takes effect at its handler\'s next restart';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Refreshing the PHP config of '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof PhpHosting) {
            $this->outcome = '  Not a PHP hosting project; nothing to refresh.';

            return;
        }

        (new PhpErrorLogConfig)->apply($runtime);
        $this->outcome = '  PHP directives written.';
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
