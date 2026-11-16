<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * GitHub webhooks: `X-GitHub-Event` names the event; only `X-Hub-Signature-256` counts, so no sender can pick SHA-1,
 * and it is checked over the raw bytes, whether the hook posts JSON or a form-encoded `payload=`.
 */
final class GithubProvider implements HookProvider
{
    use SingleRefPush;

    private const NULL_SHA = '0000000000000000000000000000000000000000';

    /** Exactly `sha256=` and 64 hex digits, so hash_equals never compares a length the sender chose. */
    private const SIGNATURE_PATTERN = '/^sha256=([0-9a-f]{64})$/i';

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

        if ($secret === '' || preg_match(self::SIGNATURE_PATTERN, $header, $m) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, strtolower($m[1]));
    }

    public function signsTheBody(Request $request, string $secret): bool
    {
        return true;
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

        return $this->pushEvent($event, $delivery, $this->payload($request));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidPayload
     */
    private function pushEvent(string $event, ?string $delivery, array $payload): HookEvent
    {
        $ref = self::pushedRef($payload);
        $after = self::pushedAfter($payload);
        $deleted = ($payload['deleted'] ?? false) === true || $after === self::NULL_SHA;

        return self::singleRefPush($this->name(), $event, $delivery, $ref, $after, $deleted);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPayload
     */
    private function payload(Request $request): array
    {
        $body = $request->getContent();

        if ($this->isFormEncoded($request)) {
            $body = $this->formPayloadField($body);
        }

        return self::decodedPush($body);
    }

    private function isFormEncoded(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/x-www-form-urlencoded');
    }

    /**
     * @throws InvalidPayload
     */
    private function formPayloadField(string $body): string
    {
        parse_str($body, $fields);
        $payload = $fields['payload'] ?? null;
        if (! is_string($payload)) {
            throw new InvalidPayload('A form-encoded push without a payload field.');
        }

        return $payload;
    }
}
