<?php

namespace App\Jobs\Middleware;

use App\Lib\Testing\CoverageRecorder;

class RecordCoverage
{
    public function handle(object $job, callable $next): mixed
    {
        $tokenId = null;
        if (method_exists($job, 'task')) {
            $task = $job->task();
            $tokenId = $task?->api_token_id;
        }

        return CoverageRecorder::around(
            $tokenId,
            static fn () => $next($job),
            'job',
        );
    }
}
