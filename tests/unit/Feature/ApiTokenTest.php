<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    #[SetsCache('api_token')]
    public function test_create_api_token(): void
    {
        $this->skipIfCached('api_token');

        $exitCode = Artisan::call('api:token:create', [
            'name' => 'testing session ' . $this->testingSessionName,
            '--short' => true,
        ]);
        $this->assertEquals(0, $exitCode);

        $token = trim(Artisan::output());
        $this->setCache('api_token', $token);
    }
}
