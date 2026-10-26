<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every provider's webhook API has in common: a bearer or header token, an
 * item URL under a collection, and JSON in and out. The differences -- the
 * collection URL, the payload, which verb updates and which field names the id
 * -- are the subclass's.
 */
abstract class ApiRegistrar implements WebhookRegistrar
{
    private const TIMEOUT = 20;

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
        try {
            $response = $this->client($token)->send($method, $url, $payload === [] ? [] : ['json' => $payload]);
        } catch (ConnectionException $e) {
            throw ProviderRegistrationRefused::unreachable($this->name(), $e->getMessage());
        }

        if (! $response->successful()) {
            throw $this->refused($response);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function client(string $token): PendingRequest
    {
        return Http::withHeaders($this->headers($token))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(10);
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
