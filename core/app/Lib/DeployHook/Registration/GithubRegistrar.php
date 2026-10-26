<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;

/**
 * GitHub repository webhooks, on github.com and GitHub Enterprise Server.
 * `POST /repos/{owner}/{repo}/hooks` with a `web` hook and a `push` event; the
 * signature goes in `config.secret` as the HMAC key.
 */
final class GithubRegistrar extends ApiRegistrar
{
    private const API = 'https://api.github.com';

    public function name(): string
    {
        return 'github';
    }

    public function hosts(): array
    {
        return ['github.com'];
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
        if (preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }

        return new WebhookTarget(
            apiBase: $host === 'github.com' ? self::API : 'https://'.$host.'/api/v3',
            repository: $m[1].'/'.$m[2],
        );
    }

    protected function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    protected function collection(WebhookTarget $target): string
    {
        return $target->apiBase.'/repos/'.$target->repository.'/hooks';
    }

    protected function updateMethod(): string
    {
        return 'PATCH';
    }

    protected function createPayload(string $url, string $secret): array
    {
        return [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $secret,
                'insecure_ssl' => '0',
            ],
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
