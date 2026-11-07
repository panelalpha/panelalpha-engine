<?php

namespace App\Lib\DeployHook;

/** Reading a push that names one ref, as GitHub and GitLab send it: `ref`, `after` and a JSON body. */
trait SingleRefPush
{
    /**
     * @return array<mixed>
     *
     * @throws InvalidPayload
     */
    private static function decodedPush(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new InvalidPayload('The push body is not JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidPayload
     */
    private static function pushedRef(array $payload): string
    {
        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            throw new InvalidPayload('A push without a ref.');
        }

        return $ref;
    }

    /** @param  array<mixed>  $payload */
    private static function pushedAfter(array $payload): ?string
    {
        return is_string($payload['after'] ?? null) ? $payload['after'] : null;
    }

    private static function singleRefPush(string $provider, string $event, ?string $deliveryId, string $ref, ?string $after, bool $deleted): HookEvent
    {
        return new HookEvent(
            provider: $provider,
            kind: HookEvent::PUSH,
            event: $event,
            deliveryId: $deliveryId,
            ref: $ref,
            branch: str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : null,
            tag: str_starts_with($ref, 'refs/tags/'),
            deleted: $deleted,
            commit: $deleted ? null : $after,
        );
    }
}
