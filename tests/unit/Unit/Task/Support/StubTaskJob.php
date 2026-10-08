<?php

namespace Tests\Unit\Task\Support;

use App\Jobs\Concerns\AttachTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StubTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use AttachTask;

    public function handle(): mixed
    {
        return $this->runTask(function () {
            return 'ok';
        });
    }
}
