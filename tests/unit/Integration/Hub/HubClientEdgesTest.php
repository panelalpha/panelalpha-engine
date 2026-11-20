<?php

namespace Tests\Integration\Hub;

use App\Lib\Hub\HubAudit;
use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use App\Models\HubConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** The Hub client's best-effort and refusing edges: a cancel that cannot be sent, a connection with no credentials. */
class HubClientEdgesTest extends TestCase
{
    public function test_a_cancel_that_cannot_reach_the_hub_is_swallowed(): void
    {
        $tried = [];
        Http::fake(static function (Request $request) use (&$tried) {
            $tried[] = [$request->url(), $request['device_code'], $request['reason']];

            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        (new HubClient('https://hub.example.com'))->cancel('device-1', 'expired');

        $this->assertSame([['https://hub.example.com/'.HubClient::SESSIONS.'/cancel', 'device-1', 'expired']], $tried);
    }

    public function test_a_signed_request_without_credentials_is_refused_before_sending(): void
    {
        Http::fake();
        $connection = (new HubConnection)->setRawAttributes(['connection_id' => 'conn-1', 'hub_secret' => null]);

        try {
            (new HubClient('https://hub.example.com'))->connection($connection);
            $this->fail('a connection without a secret must not be signed');
        } catch (HubException $e) {
            $this->assertSame('This engine holds no Hub credentials for the connection.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_an_audit_line_that_cannot_be_logged_does_not_throw(): void
    {
        Log::shouldReceive('info')->once()->with('hub.sso', ['user' => 'admin'])->andThrow(new \RuntimeException('log disk full'));

        HubAudit::log('hub.sso', ['user' => 'admin']);

        $this->addToAssertionCount(1);
    }
}
