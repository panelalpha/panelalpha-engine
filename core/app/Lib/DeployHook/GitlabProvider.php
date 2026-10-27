<?php

namespace App\Lib\DeployHook;

use App\Models\DeployHook;
use Illuminate\Http\Request;

/**
 * GitLab webhooks, from gitlab.com or a self-managed instance: an
 * `X-Gitlab-Event` header names the event (`Push Hook`, `Tag Push Hook`, ...).
 *
 * A hook with a signing token (GitLab 19.0 and later) sends Standard Webhooks
 * headers: `webhook-signature` is `v1,<base64 HMAC-SHA256>` of
 * `{webhook-id}.{webhook-timestamp}.{body}` under the `whsec_` secret, so the
 * id, the time and the body are vouched for. A delivery that carries one is
 * checked against it and nothing else, and is refused once its timestamp is
 * more than {@see SIGNATURE_TOLERANCE_SECONDS} from now.
 *
 * A delivery without one, or to a hook whose secret predates `whsec_` and so
 * cannot sign, is checked by `X-Gitlab-Token` instead, compared in constant
 * time: either the secret itself (a hook registered by hand) or
 * {@see tokenFor()} of it (a hook the engine registered, which must not send
 * its signing key as a plain header). That proves only who sent it.
 */
final class GitlabProvider implements HookProvider
{
    private const NULL_SHA = '0000000000000000000000000000000000000000';

    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /** The static token the engine registers beside a signing secret; never the secret itself. */
    public static function tokenFor(string $secret): string
    {
        return hash_hmac('sha256', 'X-Gitlab-Token', $secret);
    }

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
        if ($secret === '') {
            return false;
        }

        return $this->signsTheBody($request, $secret) ? $this->validSignature($request, $secret) : $this->validToken($request, $secret);
    }

    /** Whether GitLab can sign with this secret: only a `whsec_` one, which a hook made before 2.2 does not hold. */
    public static function canSignWith(string $secret): bool
    {
        return self::signingKey($secret) !== null;
    }

    private function validToken(Request $request, string $secret): bool
    {
        $token = $request->headers->get('X-Gitlab-Token');
        if (! is_string($token) || $token === '') {
            return false;
        }

        return $this->sameSecret($secret, $token) || $this->sameSecret(self::tokenFor($secret), $token);
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $key = self::signingKey($secret);
        $id = (string) $request->headers->get('webhook-id', '');
        $timestamp = (string) $request->headers->get('webhook-timestamp', '');
        if ($key === null || $id === '' || ! $this->isFresh($timestamp)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));

        return $this->anySignatureIs($expected, (string) $request->headers->get('webhook-signature', ''));
    }

    private static function signingKey(string $secret): ?string
    {
        if (! str_starts_with($secret, DeployHook::SIGNING_SECRET_PREFIX)) {
            return null;
        }
        $key = base64_decode(substr($secret, strlen(DeployHook::SIGNING_SECRET_PREFIX)), true);

        return $key === false || $key === '' ? null : $key;
    }

    private function isFresh(string $timestamp): bool
    {
        return ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= self::SIGNATURE_TOLERANCE_SECONDS;
    }

    /** The header is a space-separated list of `v1,<base64>` entries; one matching is enough. */
    private function anySignatureIs(string $expected, string $header): bool
    {
        foreach (preg_split('/\s+/', trim($header)) ?: [] as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hashed first so the comparison is between equal-length strings and
     * neither the secret's length nor a prefix match shows in the timing.
     */
    private function sameSecret(string $secret, string $token): bool
    {
        return hash_equals(hash('sha256', $secret, true), hash('sha256', $token, true));
    }

    /**
     * Only a signed delivery to a secret that can sign: whoever holds the token
     * can send any body, so a body seen before proves nothing.
     */
    public function signsTheBody(Request $request, string $secret): bool
    {
        return $request->headers->has('webhook-signature') && self::canSignWith($secret);
    }

    public function parse(Request $request): HookEvent
    {
        $event = (string) $request->headers->get('X-Gitlab-Event', '');

        if (! in_array($event, ['Push Hook', 'Tag Push Hook'], true)) {
            return new HookEvent($this->name(), HookEvent::OTHER, $event, $this->deliveryId($request, null, null));
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            throw new InvalidPayload('The push body is not JSON.');
        }

        return $this->pushEvent($request, $event, $payload);
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidPayload
     */
    private function pushEvent(Request $request, string $event, array $payload): HookEvent
    {
        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
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
     * present; `webhook-id` first, since a signature covers it. `X-Gitlab-Event-UUID` is not a substitute on its own: it names
     * the request or chain of webhooks that caused the event, so
     * `git push main feature` gives two push events one UUID, and treating it
     * as a delivery id would drop the second. Older GitLab omits the key; then
     * the UUID is narrowed to the ref update it arrived with, and hashed so the
     * id fits its column whatever length the sender's header has.
     */
    private function deliveryId(Request $request, ?string $ref, ?string $after): ?string
    {
        $key = $request->headers->get('webhook-id') ?: $request->headers->get('Idempotency-Key');
        if (is_string($key) && $key !== '') {
            return substr($key, 0, 128);
        }

        $uuid = $request->headers->get('X-Gitlab-Event-UUID');
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $ref === null ? substr($uuid, 0, 128) : hash('sha256', $uuid."\n".$ref."\n".(string) $after);
    }
}
