<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * GitLab webhooks, from gitlab.com or a self-managed instance: an
 * `X-Gitlab-Event` header names the event (`Push Hook`, `Tag Push Hook`, ...)
 * and `X-Gitlab-Token` carries the hook's secret token verbatim.
 *
 * There is no signature to check. GitLab sends the token as plain text, so
 * "verified" here means the sender knew the secret, and nothing about the body
 * is vouched for -- which is why the receiver reads the body only afterwards,
 * as it does for every provider. The comparison is constant-time so a sender
 * cannot learn the secret a character at a time.
 *
 * GitLab has a separate signing-token scheme (`webhook-signature`, an HMAC).
 * That is a different secret the operator pastes into GitLab, not one this
 * engine issues, so it is not used: the token field is.
 */
final class GitlabProvider implements HookProvider
{
    private const NULL_SHA = '0000000000000000000000000000000000000000';

    public function name(): string
    {
        return 'gitlab';
    }

    public function recognises(Request $request): bool
    {
        return $request->headers->has('X-Gitlab-Event');
    }

    public function verify(Request $request, string $secret): bool
    {
        $token = $request->headers->get('X-Gitlab-Token');

        if ($secret === '' || !is_string($token) || $token === '') {
            return false;
        }

        // Hashed first so the comparison is between equal-length strings and
        // neither the secret's length nor a prefix match shows in the timing.
        return hash_equals(hash('sha256', $secret, true), hash('sha256', $token, true));
    }

    public function parse(Request $request): HookEvent
    {
        $event = (string) $request->headers->get('X-Gitlab-Event', '');

        if (!in_array($event, ['Push Hook', 'Tag Push Hook'], true)) {
            return new HookEvent($this->name(), HookEvent::OTHER, $event, $this->deliveryId($request, null, null));
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new InvalidPayload('The push body is not JSON.');
        }

        $ref = $payload['ref'] ?? null;
        if (!is_string($ref) || $ref === '') {
            throw new InvalidPayload('A push without a ref.');
        }

        $isTag = str_starts_with($ref, 'refs/tags/');
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : null;
        $after = is_string($payload['after'] ?? null) ? $payload['after'] : null;
        $deleted = $after === self::NULL_SHA;

        return new HookEvent(
            provider: $this->name(),
            kind: HookEvent::PUSH,
            event: $event,
            deliveryId: $this->deliveryId($request, $ref, $after),
            ref: $ref,
            branch: $branch,
            tag: $isTag,
            deleted: $deleted,
            commit: $deleted ? null : $after,
        );
    }

    /**
     * The id a redelivery shares with the delivery it repeats.
     *
     * `Idempotency-Key` (also sent as `webhook-id`) is the one GitLab documents
     * as unchanged across retries of the same delivery, so it is used when
     * present. `X-Gitlab-Event-UUID` is not a substitute on its own: it names
     * the request or chain of webhooks that caused the event, so
     * `git push main feature` gives two push events one UUID, and treating it
     * as a delivery id would drop the second. Older GitLab omits the key; then
     * the UUID is narrowed to the ref update it arrived with.
     */
    private function deliveryId(Request $request, ?string $ref, ?string $after): ?string
    {
        $key = $request->headers->get('Idempotency-Key');
        if (is_string($key) && $key !== '') {
            return substr($key, 0, 128);
        }

        $uuid = $request->headers->get('X-Gitlab-Event-UUID');
        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        // A UUID is 36 characters, but the header is the sender's: hash the
        // parts so the id fits its column whatever they typed.
        return $ref === null ? substr($uuid, 0, 128) : hash('sha256', $uuid . "\n" . $ref . "\n" . (string) $after);
    }
}
