<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use Closure;
use Throwable;

/**
 * Fixture source site answering by "METHOD url"; anything else is a 404. No network.
 * {@see requiringToken} makes its migrator plugin refuse every call without the token.
 */
final class RoutedSourceHttpClient implements SourceHttpClient
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, SourceHttpResponse|Throwable|callable(array<string, string>, ?string): SourceHttpResponse> */
    private array $routes = [];

    /** @var (Closure(): bool)|null */
    private ?Closure $keepWaiting = null;

    private ?string $pluginToken = null;

    /**
     * The migrator plugin refuses its routes without this token, and answers
     * an unknown migration with its own error, the way a current release does.
     */
    public function requiringToken(string $token): self
    {
        $this->pluginToken = $token;

        return $this;
    }

    /**
     * @param SourceHttpResponse|Throwable|callable(array<string, string>, ?string): SourceHttpResponse $answer
     */
    public function on(string $method, string $url, SourceHttpResponse|Throwable|callable $answer): self
    {
        $this->routes[$method . ' ' . $url] = $answer;

        return $this;
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): SourceHttpResponse
    {
        if ($this->keepWaiting !== null && ! ($this->keepWaiting)()) {
            throw SourceRequestAborted::at($url);
        }
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        $answer = $this->pluginAnswer($url, $headers)
            ?? $this->routes[$method . ' ' . $url]
            ?? new SourceHttpResponse('<html>404</html>', 404, $url);
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer instanceof SourceHttpResponse ? $answer : $answer($headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function pluginAnswer(string $url, array $headers): ?SourceHttpResponse
    {
        if ($this->pluginToken === null || ! str_contains(rawurldecode($url), 'wp-migrator/v1/')) {
            return null;
        }
        if (($headers['X-Migrator-Token'] ?? null) !== $this->pluginToken) {
            return self::restError(401, 'rest_forbidden');
        }

        return str_contains($url, 'panelalpha-token-check') ? self::restError(404, 'not_found') : null;
    }

    public function postMultipart(string $url, array $fields): SourceHttpResponse
    {
        return $this->request('POST', $url, '[multipart]');
    }

    public function whileWaiting(?Closure $keepWaiting): void
    {
        $this->keepWaiting = $keepWaiting;
    }

    /**
     * What curl's progress callback does while a request waits: ask once per
     * tick, and abort the request on a false answer.
     *
     * @param callable(): void $tick
     */
    public function wait(int $ticks, callable $tick, string $url): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $tick();
            if ($this->keepWaiting !== null && ! ($this->keepWaiting)()) {
                throw SourceRequestAborted::at($url);
            }
        }
    }

    public function count(string $method, string $url): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method && $call['url'] === $url,
        ));
    }

    /**
     * Calls that carried an X-Migrator-Token, leaving out the plugin check's calls without one.
     */
    public function countWithToken(string $method, string $url): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method && $call['url'] === $url
                && isset($call['headers']['X-Migrator-Token']),
        ));
    }

    /**
     * A WordPress login page whose "back to" link names the home.
     */
    public static function loginPage(string $home, string $action): SourceHttpResponse
    {
        return new SourceHttpResponse(
            '<html><body><form name="loginform" id="loginform" action="' . $action . '" method="post"></form>'
            . '<p id="backtoblog"><a href="' . $home . '/">Go to site</a></p></body></html>',
            200,
        );
    }

    /**
     * @param list<string> $namespaces
     */
    public static function restIndex(string $home, array $namespaces = ['wp/v2']): SourceHttpResponse
    {
        return new SourceHttpResponse(
            json_encode(['home' => $home, 'url' => $home, 'namespaces' => $namespaces], JSON_THROW_ON_ERROR),
            200,
        );
    }

    public static function admin(): SourceHttpResponse
    {
        return new SourceHttpResponse('<html><script>var s={"_ajax_nonce":"n0nce42"};</script></html>', 200);
    }

    public static function restError(int $status, string $code): SourceHttpResponse
    {
        return new SourceHttpResponse(json_encode(['code' => $code, 'message' => $code], JSON_THROW_ON_ERROR), $status);
    }
}
