<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\GitlabProvider;
use Illuminate\Http\Client\Response;

/**
 * GitLab hooks: `signing_token` is the secret; `token` ({@see GitlabProvider::tokenFor()}) is for GitLab before 19.0, which
 * ignores it, and keeps the key out of headers. A secret that cannot sign goes as `token` alone: its signature is uncheckable.
 */
final class GitlabRegistrar extends ApiRegistrar
{
    use CreatedHookId;
    use RepositoryUrlParts;

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
        $parts = self::plainHttpsParts($repoUrl);
        if ($parts === null) {
            return null;
        }
        $path = trim((string) preg_replace('/\.git$/', '', (string) ($parts['path'] ?? '')), '/');
        if (substr_count($path, '/') < 1 || str_contains($path, ' ') || str_contains($path, '//')) {
            return null;
        }

        $host = self::lowerHost($parts);
        if ($host === 'gitlab.com') {
            return new WebhookTarget(self::API, $path);
        }

        return $this->selfManaged($this->origin($host, $parts), explode('/', $path));
    }

    /**
     * A self-managed GitLab may be served under a path (`https://host/gitlab`) the URL cannot tell from a
     * group: the first prefix from the root where GitLab's API answers is the instance, else the root.
     *
     * @param  list<string>  $segments
     */
    private function selfManaged(string $origin, array $segments): WebhookTarget
    {
        foreach (self::prefixLengths($segments) as $length) {
            $target = self::targetUnder($origin, $segments, $length);
            $answer = $this->probe($target->apiBase.'/version');
            if ($answer === null) {
                break;
            }
            if (self::isGitlabApi($answer)) {
                return $target;
            }
        }

        return self::targetUnder($origin, $segments, 0);
    }

    /**
     * How many leading segments may be the instance's own path: none when only
     * `namespace/project` is left, so a two-segment path is never probed.
     *
     * @param  list<string>  $segments
     * @return list<int>
     */
    private static function prefixLengths(array $segments): array
    {
        return count($segments) > 2 ? range(0, count($segments) - 2) : [];
    }

    /** @param  list<string>  $segments */
    private static function targetUnder(string $origin, array $segments, int $length): WebhookTarget
    {
        return new WebhookTarget(
            apiBase: implode('/', [$origin, ...array_slice($segments, 0, $length), 'api', 'v4']),
            repository: implode('/', array_slice($segments, $length)),
        );
    }

    /** `/version` answers its JSON to a token and a JSON 401 without one. */
    private static function isGitlabApi(Response $response): bool
    {
        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }

        return ($response->status() === 200 && isset($body['version']))
            || ($response->status() === 401 && isset($body['message']));
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
        $payload = [
            'url' => $url,
            'push_events' => true,
            'token' => GitlabProvider::tokenFor($secret),
        ];

        return GitlabProvider::canSignWith($secret) ? $payload + ['signing_token' => $secret] : $payload;
    }

    protected function hookId(array $body): string
    {
        return $this->createdHookId($body);
    }
}
