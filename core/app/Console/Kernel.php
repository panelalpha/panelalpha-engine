<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class Kernel extends ConsoleKernel
{
    /**
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();

        // prevent blade cached views persmission issues when running command as root
        if (App::runningInConsole() && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $compiledPath = (string)Config::get('view.compiled');
            Config::set('view.compiled', $compiledPath . '/console');
        }
    }

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('metrics:prune')->daily();
        $schedule->command('deploy:log:prune')->monthly();
        // Task rows are job handles; their lines are a poll buffer, not the
        // deploy archive (that is deploy:log:prune). Keep 50 newest terminal
        // deploys per project and 10 of every other job type; drop dim and
        // cap the tail. The job already trims on finish — this sweep is
        // keep-N plus a safety net. Daily because one deploy writes thousands
        // of dim lines if that trim is skipped.
        $schedule->command('task:prune')->daily()->withoutOverlapping();
        // A task row outlives its job when the job's process dies mid-run -- a
        // host reboot, an OOM kill, a worker restart -- and nothing writes the
        // terminal status that would release a poller, because the writer is
        // what died. `task:prune` cannot help (it skips non-terminal rows by
        // design). Every minute, so a client polling GET /tasks/{id} gets an
        // answer rather than waiting out its own timeout; the two-minute grace
        // period keeps it from racing a deploy that has just started.
        $schedule->command('task:reconcile --older-than=120')->everyMinute()->withoutOverlapping();
        $schedule->command('acme:challenge:prune')->hourly();
        // Expired vault entries hold ciphertext nobody can use anymore, but
        // a secret that stopped working should not outlive its usefulness on
        // disk either. Hourly, with a built-in grace hour, so a status check
        // on a just-expired ref still says `expired` rather than vanishing.
        $schedule->command('vault:purge')->hourly();
        // Renewals are exempt from Let's Encrypt's new-certificate rate
        // limit, and a certificate nobody renews is an outage with a 90-day
        // fuse. Off-peak, and never two at once.
        $schedule->command('ssl:project-cert:renew')->dailyAt('03:10')->withoutOverlapping();
        // Base images get pruned by disk-pressure reclaim and by account
        // teardown. Rebuild them off-hours so the cost never lands on a
        // customer deploy; the command is budget-capped and a no-op when the
        // cache is already warm.
        $schedule->command('system:image:prewarm')->weeklyOn(0, '03:30')->withoutOverlapping();
        // Host build caches are worth nothing to a project that is not
        // deploying -- nothing mounts them into a running application -- and
        // until this ran, nothing ever reclaimed them from one that had
        // stopped. Daily and off-hours: the window is 24h, so a project that
        // deploys daily keeps its caches and everything else pays the install
        // it would have paid anyway.
        $schedule->command('deploy:cache:prune')->dailyAt('04:15')->withoutOverlapping();
        // Deploy telemetry is written to a spool during a deploy and sent from
        // here: QUEUE_CONNECTION is `sync`, so a dispatched job would put a
        // network round trip inside the customer's deploy request.
        $schedule->command('telemetry:ship')->everyFiveMinutes()->withoutOverlapping();
        // A deploy report says an install worked. Nothing said the site
        // stopped working afterwards -- a database that went away, files
        // deleted over SFTP -- so an application could serve the engine's own
        // placeholder for a month with a green deploy behind it. Six-hourly
        // because the probe is a container round trip per account and none of
        // these failures is minute-sensitive; it exits immediately when
        // telemetry is off, since an install that does not report has nothing
        // to gain from paying for the sweep.
        $schedule->command('project:health:report')->everySixHours()->withoutOverlapping();
        // Host access logs into AWStats text databases.
        $schedule->command('stats:update')->daily()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        /** @psalm-suppress UnresolvableInclude  */
        require base_path('routes/console.php');
    }
}
