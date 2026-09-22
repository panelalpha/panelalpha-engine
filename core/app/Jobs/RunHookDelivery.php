<?php

namespace App\Jobs;

use App\Lib\DeployHook\DeliveryRunner;
use App\Models\HookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The work a queued Hook Delivery stands for: a forced pull of the checkout
 * and a rebuild from it. What it does with the outcome is DeliveryRunner's.
 */
class RunHookDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A failed deploy is recorded as a result, not retried into the same failure. */
    public int $tries = 1;

    /** The same ceiling a manual deploy has. */
    public int $timeout = 7200;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('default');
    }

    public function handle(DeliveryRunner $runner): void
    {
        $delivery = HookDelivery::find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        $runner->run($delivery);
    }

    public function failed(?Throwable $e): void
    {
        $delivery = HookDelivery::find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        app(DeliveryRunner::class)->abandon($delivery, $e ?? new \RuntimeException('The delivery job failed.'));
    }
}
