<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;

/**
 * Bitbucket Cloud repository webhooks: `POST /repositories/{workspace}/{slug}/hooks` for `repo:push`, with
 * the HMAC key in `secret`; the hook is addressed afterwards by its `uuid`.
 */
final class BitbucketCloudRegistrar extends ApiRegistrar
{
    use RepositoryUrlParts;

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
        $parts = self::httpsParts($repoUrl);
        if ($parts === null || self::lowerHost($parts) !== 'bitbucket.org' || self::carriesMoreThanAPath($parts)) {
            return null;
        }
        $repository = self::ownerAndName($parts);

        return $repository === null ? null : new WebhookTarget(self::API, $repository);
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
