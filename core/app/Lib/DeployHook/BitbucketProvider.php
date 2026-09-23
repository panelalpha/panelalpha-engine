<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * What Bitbucket Cloud and Bitbucket Data Center have in common: an
 * `X-Event-Key` header names the event, and when the webhook has a secret,
 * `X-Hub-Signature` carries `sha256=` and the hex HMAC of the raw request body
 * under it (Atlassian: "method=signature", currently sha256).
 *
 * The two products also share that header name and event-key format, so
 * neither is told apart by them alone. Cloud sends its own UUID headers
 * (`X-Hook-UUID`, `X-Request-UUID`); Data Center sends `X-Request-Id`. Each
 * subclass decides what it is from those, and nothing here reads a body.
 *
 * Only the `sha256` method is accepted. The scheme leaves room for others
 * ("this might change in the future"), and honouring a weaker one because the
 * sender chose it would let a delivery pick its own check.
 */
abstract class BitbucketProvider implements HookProvider
{
    public function verify(Request $request, string $secret): bool
    {
        $header = (string) $request->headers->get('X-Hub-Signature', '');

        // Exactly `sha256=` and 64 hex characters. Anything looser would hand
        // hash_equals a string whose length is the caller's to choose.
        if ($secret === '' || preg_match('/^sha256=([0-9a-f]{64})$/i', $header, $m) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($m[1]));
    }

    protected function eventKey(Request $request): string
    {
        return (string) $request->headers->get('X-Event-Key', '');
    }

    /** Whether the request carries Bitbucket Cloud's own identifying headers. */
    protected function hasCloudHeaders(Request $request): bool
    {
        return $request->headers->has('X-Hook-UUID') || $request->headers->has('X-Request-UUID');
    }

    /**
     * A request id from a header, cut to the delivery_id column. The header is
     * the sender's, so the length is not.
     */
    protected function requestId(Request $request, string $header): ?string
    {
        $id = $request->headers->get($header);

        return is_string($id) && $id !== '' ? substr($id, 0, 128) : null;
    }

    /**
     * The push event for a payload's list of changes.
     *
     * A change the provider cannot read is left out rather than failing the
     * delivery: one push can move several refs, and a malformed entry beside
     * the tracked branch's must not stop that one from deploying. Only a list
     * with nothing readable in it is an invalid payload.
     *
     * @param callable(mixed): ?RefChange $toChange null for a change it cannot read
     * @throws InvalidPayload
     */
    protected function pushEvent(string $event, ?string $delivery, mixed $changes, callable $toChange): HookEvent
    {
        if (!is_array($changes)) {
            throw new InvalidPayload('A push without changes.');
        }

        $readable = [];
        foreach ($changes as $change) {
            $ref = $toChange($change);
            if ($ref !== null) {
                $readable[] = $ref;
            }
        }

        if ($changes !== [] && $readable === []) {
            throw new InvalidPayload('A push none of whose changes can be read.');
        }

        return HookEvent::push($this->name(), $event, $delivery, $readable);
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidPayload
     */
    protected function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            throw new InvalidPayload('The push body is not JSON.');
        }

        return $decoded;
    }
}
