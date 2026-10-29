<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;

/**
 * Bitbucket Cloud repository webhooks. `POST /repositories/{workspace}/{slug}/hooks`
 * with the `repo:push` event; the `secret` field is the HMAC key the receiver
 * checks, and the hook is addressed afterwards by its `uuid`, not an int.
 */
final class BitbucketCloudRegistrar extends ApiRegistrar
{
    private const API = 'https://api.bitbucket.org/2.0';

    public function name(): string
    {
        return 'bitbucket-cloud';
    }

    public function hosts(): array
    {
        return ['bitbucket.org'];
    }

    public function address(string $repoUrl): ?WebhookTarget
    {
        $parts = parse_url(trim($repoUrl));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'bitbucket.org' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }

        return new WebhookTarget(self::API, $m[1].'/'.$m[2]);
    }

    protected function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    protected function collection(WebhookTarget $target): string
    {
        return $target->apiBase.'/repositories/'.$target->repository.'/hooks';
    }

    protected function createPayload(string $url, string $secret): array
    {
        return [
            'description' => 'PanelAlpha deploy hook',
            'url' => $url,
            'active' => true,
            'events' => ['repo:push'],
            'secret' => $secret,
        ];
    }

    protected function hookId(array $body): string
    {
        $uuid = $body['uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            throw ProviderRegistrationRefused::unreachable($this->name(), 'the created webhook had no uuid');
        }

        return $uuid;
    }
}
