<?php

namespace App\Lib\DeployHook\Registration;

/**
 * GitHub and GitHub Enterprise Server repository webhooks: `POST /repos/{owner}/{repo}/hooks`, a `web`
 * hook for `push`, with the HMAC key in `config.secret`.
 */
final class GithubRegistrar extends ApiRegistrar
{
    use CreatedHookId;
    use RepositoryUrlParts;

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
        $parts = self::plainHttpsParts($repoUrl);
        if ($parts === null) {
            return null;
        }
        $repository = self::ownerAndName($parts);
        if ($repository === null) {
            return null;
        }
        $host = self::lowerHost($parts);

        return new WebhookTarget(
            apiBase: $host === 'github.com' ? self::API : $this->origin($host, $parts).'/api/v3',
            repository: $repository,
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
        return $this->createdHookId($body);
    }
}
