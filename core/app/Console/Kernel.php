<?php

namespace App\Console;

use App\Exceptions\Handler;
use App\Support\RootConsoleOwnership;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Throwable;

class Kernel extends ConsoleKernel
{
    /**
     * `withoutOverlapping()` expiry in minutes, per cadence. Laravel's default
     * is 1440, and a `schedule:run` killed by a core restart never releases its
     * mutex, so a stale one should cost a run or two rather than a day.
     */
    private const MUTEX_MINUTE = 5;

    private const MUTEX_FIVE_MINUTES = 5;

    private const MUTEX_HOURLY = 50;

    private const MUTEX_SIX_HOURS = 300;

    private const MUTEX_DAILY = 720;

    private const MUTEX_WEEKLY = 1000;

    /**
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();

        if (App::runningInConsole() && self::effectiveUid() === 0) {
            $this->separateRootViewCache();
        }

        if (RootConsoleOwnership::applies(App::runningInConsole(), self::effectiveUid(), App::runningUnitTests())) {
            RootConsoleOwnership::register();
        }
    }

    /**
     * Collision (a dev dependency, installed on hosts) replaces the exception
     * handler's console rendering with a stack dump; a failure the user can
     * act on stays one plain line either way.
     */
    protected function renderException($output, Throwable $e)
    {
        if (!Handler::renderPlainForConsole($output, $e)) {
            parent::renderException($output, $e);
        }
    }

    /**
     * Define the application's command schedule. Registration order is the
     * order in which commands due in the same minute run.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $this->scheduleLogPruning($schedule);
        $this->scheduleInterruptedWorkRecovery($schedule);
        $this->scheduleExpiredSecretPurges($schedule);
        $this->scheduleTunnelExpiry($schedule);
        $this->scheduleCertificateRenewal($schedule);
        $this->scheduleHostDiskUpkeep($schedule);
        $this->scheduleMonitoring($schedule);
    }

    /** The uid commands run as, or -1 when POSIX functions are unavailable. */
    private static function effectiveUid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : -1;
    }

    /** Compile root's Blade views apart, so files it writes never block the web user's own. */
    private function separateRootViewCache(): void
    {
        $compiledPath = (string)Config::get('view.compiled');
        Config::set('view.compiled', $compiledPath . '/console');
    }

    /**
     * Metrics, the deploy log archive, and task rows. Task lines are a poll
     * buffer, not the deploy archive: keep the newest 50 deploys per project and
     * 10 of every other job; daily, since one untrimmed deploy writes thousands of lines.
     */
    private function scheduleLogPruning(Schedule $schedule): void
    {
        $schedule->command('metrics:prune')->daily()->withoutOverlapping(self::MUTEX_DAILY);
        $schedule->command('deploy:log:prune')->monthly()->withoutOverlapping(self::MUTEX_WEEKLY);
        $schedule->command('task:prune')->daily()->withoutOverlapping(self::MUTEX_DAILY);
    }

    /**
     * Every minute, finish what a dead process left behind: a task row nobody
     * will mark terminal (after a two-minute grace, so a starting deploy is not
     * raced) and a redeploy halfway between app generations (only when its deploy lock is free).
     */
    private function scheduleInterruptedWorkRecovery(Schedule $schedule): void
    {
        $schedule->command('task:reconcile --older-than=120')->everyMinute()->withoutOverlapping(self::MUTEX_MINUTE);
        $schedule->command('deploy:generations:sweep')->everyMinute()->withoutOverlapping(self::MUTEX_MINUTE);
    }

    /**
     * Hourly removal of ACME challenges, expired vault entries (after a grace
     * hour, so a status check still says `expired`) and spent or expired SSO
     * tokens, since an unredeemed app token still holds a live session cookie.
     */
    private function scheduleExpiredSecretPurges(Schedule $schedule): void
    {
        $schedule->command('acme:challenge:prune')->hourly()->withoutOverlapping(self::MUTEX_HOURLY);
        $schedule->command('vault:purge')->hourly()->withoutOverlapping(self::MUTEX_HOURLY);
        $schedule->command('model:prune', [
            '--model' => [\App\Models\AppSsoToken::class, \App\Models\MysqlSsoToken::class],
        ])->hourly()->withoutOverlapping(self::MUTEX_HOURLY);
    }

    /**
     * A tunnel carries a lifetime; a request never serves one past it, and this
     * removes its registration and the domain's answer to it. Frequent enough
     * that a preview's day is honoured to within a few minutes.
     */
    private function scheduleTunnelExpiry(Schedule $schedule): void
    {
        $schedule->command('tunnels:expire')->everyFiveMinutes()->withoutOverlapping(self::MUTEX_FIVE_MINUTES);
    }

    /** Renewals are exempt from Let's Encrypt's new-certificate rate limit; off-peak, never two at once. */
    private function scheduleCertificateRenewal(Schedule $schedule): void
    {
        $schedule->command('ssl:project-cert:renew')->dailyAt('03:10')->withoutOverlapping(self::MUTEX_DAILY);
    }

    /**
     * Rebuild base images off-hours so no customer deploy pays for it, and free
     * host disk: build caches of projects idle for 24h, images outside the
     * catalogue (hourly, as it defers while a deploy runs) and, when space runs low, more.
     */
    private function scheduleHostDiskUpkeep(Schedule $schedule): void
    {
        $schedule->command('system:image:prewarm --refresh')->weeklyOn(0, '03:30')->withoutOverlapping(self::MUTEX_WEEKLY);
        $schedule->command('deploy:cache:prune')->dailyAt('04:15')->withoutOverlapping(self::MUTEX_DAILY);
        $schedule->command('system:image:prune --due-after=20h')->hourly()->withoutOverlapping(self::MUTEX_HOURLY);
        $schedule->command('system:disk:guard')->everyFiveMinutes()->withoutOverlapping(self::MUTEX_FIVE_MINUTES);
    }

    /**
     * Ship spooled telemetry (QUEUE_CONNECTION is `sync`, so a queued job would
     * sit inside the deploy request), repair accounts sysbox unmapped, sweep
     * application health, and update the AWStats databases.
     */
    private function scheduleMonitoring(Schedule $schedule): void
    {
        $schedule->command('telemetry:ship')->everyFiveMinutes()->withoutOverlapping(self::MUTEX_FIVE_MINUTES);
        $schedule->command('project:mapping:check')->everyFiveMinutes()->withoutOverlapping(self::MUTEX_FIVE_MINUTES);
        $schedule->command('project:health:report')->everySixHours()->withoutOverlapping(self::MUTEX_SIX_HOURS);
        $schedule->command('stats:update')->daily()->withoutOverlapping(self::MUTEX_DAILY);
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
