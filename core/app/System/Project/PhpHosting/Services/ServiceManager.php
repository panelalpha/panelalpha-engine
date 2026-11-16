<?php

namespace App\System\Project\PhpHosting\Services;

use App\System\Project\PhpHosting;

/**
 * What keeps a PHP hosting account's processes up: the PHP handlers, Apache, redis and cron.
 * Callers name a service; which init runs it is this class's business. Chosen in {@see PhpHosting::services()}.
 */
interface ServiceManager
{
    /** Exit status of {@see restartScript()} when the account has no such service. */
    public const EXIT_NOT_MANAGED = 3;

    /**
     * Init scripts (file => shell) that start, once at boot, the $services
     * this init does not run in the foreground.
     *
     * @param  list<Service>  $services
     * @return array<string, string>
     */
    public function bootScripts(array $services): array;

    /**
     * Write $services into the project dir and drop any other service written there.
     * A running account picks them up through {@see sync()}.
     *
     * @param  list<Service>  $services
     */
    public function write(array $services): void;

    /** In the running account: start what {@see write()} added, stop what it dropped. */
    public function sync(): void;

    /** Have $service pick up a changed configuration, without waiting for it. */
    public function reload(string $service): void;

    /**
     * Inside the account: restart $service with its current command.
     *
     * @return list<string>
     */
    public function restartArgv(string $service): array;

    /**
     * Shell restarting or starting $service, or exiting {@see EXIT_NOT_MANAGED} untouched without one; a copy of $mainProcess
     * (`pgrep -f`) the init did not start is stopped first and named on stdout, its only output there. Ends without a newline.
     */
    public function restartScript(string $service, string $mainProcess): string;
}
