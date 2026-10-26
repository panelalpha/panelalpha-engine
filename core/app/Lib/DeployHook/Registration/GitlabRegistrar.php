<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\ProviderRegistrationRefused;

/**
 * GitLab project webhooks, on gitlab.com and self-managed instances. `POST
 * /projects/:id/hooks` with `push_events`. The secret is the hook's
 * `signing_token`, so GitLab 19.0 and later sign every delivery; `token` is
 * derived from it ({@see GitlabProvider::tokenFor()}) for older GitLab, which
 * ignores the signing token, and so that no header ever carries the key.
 */
final class GitlabRegistrar extends ApiRegistrar
{
    private const API = 'https://gitlab.com/api/v4';

    public function name(): string
    {
        return 'gitlab';
    }

    public function hosts(): array
    {
        return ['gitlab.com'];
    }

    public function address(string $repoUrl): ?WebhookTarget
    {
        $parts = parse_url(trim($repoUrl));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = trim((string) preg_replace('/\.git$/', '', (string) ($parts['path'] ?? '')), '/');
        if (substr_count($path, '/') < 1 || str_contains($path, ' ') || str_contains($path, '//')) {
            return null;
        }

        return new WebhookTarget(
            apiBase: $host === 'gitlab.com' ? self::API : 'https://'.$host.'/api/v4',
            repository: $path,
        );
    }

    protected function headers(string $token): array
    {
        return ['PRIVATE-TOKEN' => $token];
    }

    protected function collection(WebhookTarget $target): string
    {
        return $target->apiBase.'/projects/'.rawurlencode($target->repository).'/hooks';
    }

    protected function createPayload(string $url, string $secret): array
    {
        return [
            'url' => $url,
            'push_events' => true,
            'token' => GitlabProvider::tokenFor($secret),
            'signing_token' => $secret,
        ];
    }

    protected function hookId(array $body): string
    {
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            throw ProviderRegistrationRefused::unreachable($this->name(), 'the created webhook had no id');
        }

        return (string) $id;
    }
}
