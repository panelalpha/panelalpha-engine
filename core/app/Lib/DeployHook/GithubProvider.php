<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * GitHub webhooks: an `X-GitHub-Event` header names the event, and
 * `X-Hub-Signature-256` carries `sha256=` and the hex HMAC of the raw request
 * body under the hook's secret.
 *
 * Only the SHA-256 header is accepted. GitHub also sends a SHA-1 one for
 * compatibility, and honouring it would let a delivery choose the weaker
 * check by leaving the other out.
 *
 * A hook can be registered as `application/json` or as
 * `application/x-www-form-urlencoded`. The signature is over the bytes either
 * way -- for the second, the whole encoded body, `payload=` and all -- so
 * verification never depends on the content type and never touches a decoded
 * body.
 */
final class GithubProvider implements HookProvider
{
    private const NULL_SHA = '0000000000000000000000000000000000000000';

    public function name(): string
    {
        return 'github';
    }

    public function recognises(Request $request): bool
    {
        return $request->headers->has('X-GitHub-Event');
    }

    public function verify(Request $request, string $secret): bool
    {
        $header = (string) $request->headers->get('X-Hub-Signature-256', '');

        // Exactly `sha256=` and 64 hex characters. Anything looser would hand
        // hash_equals a string whose length is the caller's to choose.
        if (preg_match('/^sha256=([0-9a-f]{64})$/i', $header, $m) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, strtolower($m[1]));
    }

    public function parse(Request $request): HookEvent
    {
        $event = (string) $request->headers->get('X-GitHub-Event', '');
        $delivery = $request->headers->get('X-GitHub-Delivery');
        $delivery = is_string($delivery) && $delivery !== '' ? $delivery : null;

        if ($event === 'ping') {
            return new HookEvent($this->name(), HookEvent::PING, $event, $delivery);
        }

        if ($event !== 'push') {
            return new HookEvent($this->name(), HookEvent::OTHER, $event, $delivery);
        }

        $payload = $this->payload($request);
        $ref = $payload['ref'] ?? null;
        if (!is_string($ref) || $ref === '') {
            throw new InvalidPayload('A push without a ref.');
        }

        $isTag = str_starts_with($ref, 'refs/tags/');
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : null;
        $after = is_string($payload['after'] ?? null) ? $payload['after'] : null;
        $deleted = ($payload['deleted'] ?? false) === true || $after === self::NULL_SHA;

        return new HookEvent(
            provider: $this->name(),
            kind: HookEvent::PUSH,
            event: $event,
            deliveryId: $delivery,
            ref: $ref,
            branch: $branch,
            tag: $isTag,
            deleted: $deleted,
            commit: $deleted ? null : $after,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $body = $request->getContent();

        if (str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/x-www-form-urlencoded')) {
            parse_str($body, $fields);
            $body = $fields['payload'] ?? null;
            if (!is_string($body)) {
                throw new InvalidPayload('A form-encoded push without a payload field.');
            }
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new InvalidPayload('The push body is not JSON.');
        }

        return $decoded;
    }
}
