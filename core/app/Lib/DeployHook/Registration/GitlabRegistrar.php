<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\GitlabProvider;
use App\Lib\DeployHook\ProviderRegistrationRefused;
use Illuminate\Http\Client\Response;

/**
 * GitLab project webhooks, on gitlab.com and self-managed instances. `POST
 * /projects/:id/hooks` with `push_events`. The secret is the hook's
 * `signing_token`, so GitLab 19.0 and later sign every delivery; `token` is
 * derived from it ({@see GitlabProvider::tokenFor()}) for older GitLab, which
 * ignores the signing token, and so that no header ever carries the key. A
 * secret that cannot sign ({@see GitlabProvider::canSignWith()}) is sent as
 * the `token` alone: GitLab would sign with it, and the engine could not check that.
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

        if ($host === 'gitlab.com') {
            return new WebhookTarget(self::API, $path);
        }

        return $this->selfManaged($this->origin($host, $parts), explode('/', $path));
    }

    /**
     * A self-managed GitLab may be served under a path (`https://host/gitlab`), which the
     * repository URL alone cannot tell from a group. From the root down, the first prefix
     * where GitLab's API answers is the instance; when none does, the root, as before.
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
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            throw ProviderRegistrationRefused::unreachable($this->name(), 'the created webhook had no id');
        }

        return (string) $id;
    }
}
