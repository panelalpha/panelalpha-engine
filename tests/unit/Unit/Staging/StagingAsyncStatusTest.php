<?php

namespace Tests\Unit\Staging;

use App\Models\User;
use Tests\TestCase;

class StagingAsyncStatusTest extends TestCase
{
    public function test_merge_async_status_keeps_sibling_keys(): void
    {
        $user = new User();
        $user->username = 'live';
        $user->details = [
            'async_status' => [
                'staging' => 'running',
                'source'  => 'api',
            ],
        ];

        $user->mergeAsyncStatus(['push' => 'running']);

        $async = $user->asyncStatus();
        $this->assertSame('running', $async['staging']);
        $this->assertSame('api', $async['source']);
        $this->assertSame('running', $async['push']);
    }
}
