<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use Closure;
use RuntimeException;

/**
 * Fixture-only HTTP client for source WordPress tests. No network.
 */
final class FakeSourceHttpClient implements SourceHttpClient
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $calls = [];

    /** @var list<callable(string, string, ?string): SourceHttpResponse> */
    private array $handlers = [];

    /**
     * @param callable(string, string, ?string): SourceHttpResponse $handler
     */
    public function pushHandler(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
    ): SourceHttpResponse {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->handlers === []) {
            throw new RuntimeException('FakeSourceHttpClient has no handler for ' . $method . ' ' . $url);
        }

        $handler = array_shift($this->handlers);

        return $handler($method, $url, $body);
    }

    public function postMultipart(string $url, array $fields): SourceHttpResponse
    {
        return $this->request('POST', $url, '[multipart]');
    }

    public function whileWaiting(?Closure $keepWaiting): void
    {
    }

    public function calledUrlContaining(string $needle): bool
    {
        foreach ($this->calls as $call) {
            if (str_contains($call['url'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
