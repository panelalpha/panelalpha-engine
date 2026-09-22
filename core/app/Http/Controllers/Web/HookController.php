<?php

namespace App\Http\Controllers\Web;

use App\Lib\DeployHook\HookReceiver;
use App\Models\DeployHook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a git host's push notification lands.
 *
 * Unauthenticated in the bearer sense, by design: GitHub cannot send a token
 * this engine issued. The credential is the signature -- an HMAC of the body
 * under a per-hook secret -- and the address is unguessable, so a request has
 * to know both to be acted on. Registered in `routes/hooks.php`, outside the
 * `api` and `web` groups, so it starts no session, sets no cookie and is not
 * subject to CSRF (which a host's server-to-server POST could never satisfy).
 *
 * An address that is not a hook is a bare 404 and is not recorded: there is no
 * hook to attach the request to, and recording it would let anyone who can
 * reach the engine fill the table.
 */
class HookController
{
    public function receive(Request $request, string $publicId, HookReceiver $receiver): JsonResponse
    {
        $hook = DeployHook::query()->where('public_id', $publicId)->first();

        // A hook whose project is gone answers exactly like one that never was.
        if ($hook === null || $hook->user === null) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        $response = $receiver->receive($hook, $request);

        return new JsonResponse($response->body, $response->status);
    }
}
