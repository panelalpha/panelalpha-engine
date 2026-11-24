<?php

namespace App\Jobs;

use App\Lib\Hub\HubBackfill;
use App\Lib\Hub\HubBackfillWindow;
use App\Models\HubConnection;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One history window for the Hub, off the minute report's clock; the next window it asks for is the next job. */
class SendHubBackfill implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    /** @param  array{from: string, to: string, step: int}  $window */
    public function __construct(public array $window)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $connection = HubConnection::active();
        $window = HubBackfillWindow::from($this->window);
        if ($connection === null || ! $connection->isPush() || $window === null) {
            return;
        }

        $next = (new HubBackfill)->send($connection, $window);
        if ($next !== null) {
            self::dispatch($next->toArray());
        }
    }
}
