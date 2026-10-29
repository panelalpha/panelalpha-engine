<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;
use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressGuard;
use App\Lib\Net\ResolvedTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;

/**
 * What every provider's webhook API has in common: a bearer or header token, an
 * item URL under a collection, and JSON in and out. The differences -- the
 * collection URL, the payload, which verb updates and which field names the id
 * -- are the subclass's.
 *
 * The API host comes from the repository URL a tenant connected, so every call
 * passes the egress guard first, is pinned to the address it checked, and
 * follows a redirect only on that same host and port.
 */
abstract class ApiRegistrar implements WebhookRegistrar
{
    private const TIMEOUT = 20;

    private const PROBE_TIMEOUT = 10;

    private const SCHEMES = ['https'];

    /** @return array<string, string> */
    abstract protected function headers(string $token): array;

    /** The URL new webhooks are posted to. */
    abstract protected function collection(WebhookTarget $target): string;

    /** @return array<string, mixed> */
    abstract protected function createPayload(string $url, string $secret): array;

    /**
     * @param  array<string, mixed>  $body
     * @return string the provider's id for the new hook
     */
    abstract protected function hookId(array $body): string;

    /** The verb that replaces an existing hook; PATCH for GitHub, PUT for the rest. */
    protected function updateMethod(): string
    {
        return 'PUT';
    }

    /**
     * `https://` and the host of a parsed repository URL, with its port, so a
     * self-managed instance is asked where its repository is served.
     *
     * @param  array<string, mixed>  $parts
     */
    protected function origin(string $host, array $parts): string
    {
        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function create(WebhookTarget $target, string $token, string $url, string $secret): string
    {
        $body = $this->send('POST', $this->collection($target), $token, $this->createPayload($url, $secret));

        return $this->hookId($body);
    }

    public function update(WebhookTarget $target, string $token, string $hookId, string $url, string $secret): void
    {
        $this->send($this->updateMethod(), $this->item($target, $hookId), $token, $this->createPayload($url, $secret));
    }

    public function delete(WebhookTarget $target, string $token, string $hookId): void
    {
        $this->send('DELETE', $this->item($target, $hookId), $token);
    }

    protected function item(WebhookTarget $target, string $hookId): string
    {
        return $this->collection($target).'/'.rawurlencode($hookId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the decoded body, empty for a 204
     *
     * @throws ProviderRegistrationRefused
     */
    protected function send(string $method, string $url, string $token, array $payload = []): array
    {
        $target = $this->allowedTarget($url);
        try {
            $response = $this->client($token, $target)->send($method, $url, $payload === [] ? [] : ['json' => $payload]);
        } catch (ConnectionException $e) {
            throw ProviderRegistrationRefused::unreachable($this->name(), $e->getMessage());
        }

        if (! $response->successful()) {
            throw $this->refused($response);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * GET $url with no credentials, through the egress guard, pinned to the address it checked
     * and following no redirect; null when the host is refused or cannot be reached. The one
     * call made to learn where a provider's API lives rather than to use it.
     */
    protected function probe(string $url): ?Response
    {
        try {
            $target = $this->allowedTarget($url);
        } catch (ProviderRegistrationRefused) {
            return null;
        }
        try {
            return Http::acceptJson()
                ->timeout(self::PROBE_TIMEOUT)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions($this->pinOption($target))
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * One refusal whatever the reason, so the answer never tells a host that
     * resolves to an internal address from one that does not resolve.
     *
     * @throws ProviderRegistrationRefused
     */
    private function allowedTarget(string $url): ResolvedTarget
    {
        try {
            return EgressGuard::assertAllowed($url, self::SCHEMES);
        } catch (\InvalidArgumentException) {
            throw ProviderRegistrationRefused::hostRefused($this->name());
        }
    }

    private function client(string $token, ResolvedTarget $target): PendingRequest
    {
        return Http::withHeaders($this->headers($token))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(10)
            ->withOptions($this->pinnedOptions($target));
    }

    /** @return array<string, mixed> */
    private function pinnedOptions(ResolvedTarget $target): array
    {
        return ['allow_redirects' => [
            'max' => OutboundUrl::MAX_REDIRECTS,
            'strict' => true,
            'referer' => false,
            'on_redirect' => fn ($request, $response, UriInterface $next) => $this->refuseLeaving($target, $next),
        ]] + $this->pinOption($target);
    }

    /** @return array<string, mixed> curl connects to the address the guard checked, never resolving the host again */
    private function pinOption(ResolvedTarget $target): array
    {
        $pin = $target->pin();

        return $pin !== null ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : [];
    }

    /** @throws ProviderRegistrationRefused */
    private function refuseLeaving(ResolvedTarget $target, UriInterface $next): void
    {
        $sameHost = strtolower($next->getScheme()) === 'https'
            && strtolower(trim($next->getHost(), '[]')) === strtolower($target->host)
            && ($next->getPort() ?? 443) === $target->port;
        if (! $sameHost) {
            throw ProviderRegistrationRefused::hostRefused($this->name());
        }
    }

    /**
     * 401, 403 and 404 are the three ways a provider says the token may not
     * manage this repository's webhooks; a 404 is how GitHub hides a private
     * repository the token cannot see.
     */
    private function refused(Response $response): ProviderRegistrationRefused
    {
        $status = $response->status();

        return in_array($status, [401, 403, 404], true)
            ? ProviderRegistrationRefused::permissionDenied($this->name(), $status)
            : ProviderRegistrationRefused::unreachable($this->name(), "the provider answered {$status}");
    }
}
