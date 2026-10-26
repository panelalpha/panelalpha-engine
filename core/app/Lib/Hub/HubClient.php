<?php

namespace App\Lib\Hub;

use App\Lib\Apis\PanelAlpha;
use App\Models\HubConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Calls from this engine to the Hub: the connect ceremony, and afterwards a few
 * signed notices. TLS verification is never turned off and redirects are never
 * followed; a network failure is a HubException, a Hub answer is a HubResponse.
 */
class HubClient
{
    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 15;

    public const SESSIONS = 'api/engine/v1/connect-sessions';

    public const CONNECTION = 'api/engine/v1/connection';

    private string $base;

    /** @throws HubException when the Hub URL is not allowed */
    public function __construct(?string $base = null)
    {
        $this->base = $base ?? HubUrl::base();
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws HubException
     */
    public function createSession(array $body): HubResponse
    {
        return $this->post(self::SESSIONS, $body);
    }

    /** @throws HubException */
    public function poll(string $deviceCode): HubResponse
    {
        return $this->post(self::SESSIONS.'/poll', ['device_code' => $deviceCode]);
    }

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws HubException
     */
    public function complete(string $deviceCode, array $credential, string $tlsSpki): HubResponse
    {
        return $this->post(self::SESSIONS.'/complete', [
            'device_code' => $deviceCode,
            'credential' => $credential,
            'tls_spki' => $tlsSpki,
        ]);
    }

    /** Best effort: never throws. */
    public function cancel(string $deviceCode, string $reason): void
    {
        try {
            $this->post(self::SESSIONS.'/cancel', ['device_code' => $deviceCode, 'reason' => $reason]);
        } catch (\Throwable) {
        }
    }

    /** @throws HubException */
    public function connection(HubConnection $connection): HubResponse
    {
        return $this->signed($connection, 'GET', self::CONNECTION, null);
    }

    /** @throws HubException */
    public function disconnect(HubConnection $connection, string $reason): HubResponse
    {
        return $this->signed($connection, 'POST', self::CONNECTION.'/disconnect', ['reason' => $reason]);
    }

    /** @throws HubException */
    public function announcePin(HubConnection $connection, string $nextSpki): HubResponse
    {
        return $this->signed($connection, 'POST', self::CONNECTION.'/tls-pin', ['next_tls_spki' => $nextSpki]);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws HubException
     */
    private function post(string $path, array $body): HubResponse
    {
        return $this->send('POST', $path, (string) json_encode($body, JSON_UNESCAPED_SLASHES), []);
    }

    /**
     * Signs the exact bytes it sends. A 409 is a replay: re-signed with a new
     * timestamp and sent once more.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws HubException
     */
    private function signed(HubConnection $connection, string $method, string $path, ?array $body): HubResponse
    {
        $secret = (string) $connection->hub_secret;
        $id = (string) $connection->connection_id;
        if ($secret === '' || $id === '') {
            throw new HubException('This engine holds no Hub credentials for the connection.');
        }

        $raw = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $response = $this->send($method, $path, $raw, HubSigner::headers($id, $secret, $method, $path, $raw));

        if ($response->status === 409) {
            Sleep::for(1)->second();
            $response = $this->send($method, $path, $raw, HubSigner::headers($id, $secret, $method, $path, $raw));
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws HubException
     */
    private function send(string $method, string $path, string $raw, array $headers): HubResponse
    {
        $request = $this->request()->withHeaders($headers);
        if ($raw !== '') {
            $request = $request->withBody($raw, 'application/json');
        }

        try {
            $response = $request->send($method, PanelAlpha::url($this->base, $path));
        } catch (ConnectionException $e) {
            throw new HubException('Could not reach PanelAlpha Connect at '.$this->base.': '.$this->reason($e));
        }

        $json = $response->json();
        $retry = $response->header('Retry-After');

        return new HubResponse(
            $response->status(),
            is_array($json) ? $json : [],
            is_numeric($retry) ? (int) $retry : null,
        );
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(PanelAlpha::identityHeaders())
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withOptions(['allow_redirects' => false, 'verify' => true]);
    }

    /** Guzzle's message carries the URL, never a body; keep it to one line. */
    private function reason(ConnectionException $e): string
    {
        return trim((string) strtok($e->getMessage(), "\n"));
    }
}
