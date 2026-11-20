<?php

namespace Tests\Integration\DeployHook;

use App\Lib\DeployHook\HookReceiver;
use App\Models\HookDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/** A push to a ref that is neither a branch nor a tag is recorded as ignored, naming the ref. */
class HookReceiverNonBranchRefTest extends DeployHookTestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    public function test_a_push_to_a_notes_ref_is_202_and_ignored_with_the_ref(): void
    {
        Queue::fake();
        $hook = $this->hook($this->user('main'), self::SECRET);
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/github-hooks/push-main.json'), true);
        $payload['ref'] = 'refs/notes/commits';
        $body = (string) json_encode($payload);
        $request = Request::create('/hooks/'.$hook->public_id, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'guid-notes',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);

        $response = (new HookReceiver)->receive($hook, $request);

        $this->assertSame(202, $response->status);
        $delivery = HookDelivery::sole();
        $this->assertSame(HookDelivery::OUTCOME_IGNORED, $delivery->outcome);
        $this->assertSame('not a branch push: refs/notes/commits', $delivery->reason);
        Queue::assertNothingPushed();
    }
}
