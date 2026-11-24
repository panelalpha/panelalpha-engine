<?php

namespace App\Lib\Hub;

use App\Lib\Apis\PanelAlpha;
use App\Models\HubConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Calls to the Hub: the connect ceremony, the minute report and its backfill uploads, a few signed notices, and the
 * public release list. TLS is always verified and redirects never followed; a network failure is a HubException, a Hub
 * answer is a HubResponse.
 */
class HubClient
{
    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 15;

    public const SESSIONS = 'api/engine/v1/connect-sessions';

    public const CONNECTION = 'api/engine/v1/connection';

    public const RELEASES = 'api/v1/public/releases';

    public const REPORT = 'api/engine/v1/report';

    public const METRICS = 'api/engine/v1/metrics';

    /** A report is never retried within its minute, so it gives up well before the next one. */
    public const REPORT_TIMEOUT = 10;

    /** Bodies above this go gzipped; the Hub accepts either and signs the bytes as sent. */
    private const GZIP_FROM_BYTES = 262144;

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
     * A push connection hands the Hub nothing: no token, no pin.
     *
     * @throws HubException
     */
    public function complete(string $deviceCode): HubResponse
    {
        return $this->post(self::SESSIONS.'/complete', ['device_code' => $deviceCode]);
    }

    /** Best effort: never throws. */
    public function cancel(string $deviceCode, string $reason): void
    {
        try {
            $this->post(self::SESSIONS.'/cancel', ['device_code' => $deviceCode, 'reason' => $reason]);
        } catch (\Throwable) {
        }
    }

    /**
     * The newest release of every PanelAlpha product, unsigned.
     *
     * @throws HubException
     */
    public function releases(): HubResponse
    {
        return $this->send('GET', self::RELEASES, '', []);
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
     * @param  array<string, mixed>  $report
     *
     * @throws HubException
     */
    public function report(HubConnection $connection, array $report): HubResponse
    {
        return $this->signed($connection, 'POST', self::REPORT, $report, self::REPORT_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $upload
     *
     * @throws HubException
     */
    public function metrics(HubConnection $connection, array $upload): HubResponse
    {
        return $this->signed($connection, 'POST', self::METRICS, $upload);
    }

    /** @throws HubException */
    public function nextSecret(HubConnection $connection): HubResponse
    {
        return $this->signed($connection, 'POST', self::CONNECTION.'/secret', null);
    }

    /** @throws HubException */
    public function upgrade(HubConnection $connection): HubResponse
    {
        return $this->signed($connection, 'POST', self::CONNECTION.'/upgrade', ['protocol' => HubConnection::PROTOCOL_PUSH]);
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
     * Signs the exact bytes it sends. A 409 without a `code` is a replay: re-signed with a new timestamp and sent once
     * more.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws HubException
     */
    private function signed(HubConnection $connection, string $method, string $path, ?array $body, int $timeout = self::TIMEOUT): HubResponse
    {
        $secret = (string) $connection->hub_secret;
        $id = (string) $connection->connection_id;
        if ($secret === '' || $id === '') {
            throw new HubException('This engine holds no Hub credentials for the connection.');
        }

        $raw = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = [];
        if (strlen($raw) >= self::GZIP_FROM_BYTES) {
            $raw = (string) gzencode($raw);
            $headers['Content-Encoding'] = 'gzip';
        }
        $response = $this->send($method, $path, $raw, $headers + HubSigner::headers($id, $secret, $method, $path, $raw), $timeout);

        if ($response->status === 409 && $response->code() === null) {
            Sleep::for(1)->second();
            $response = $this->send($method, $path, $raw, $headers + HubSigner::headers($id, $secret, $method, $path, $raw), $timeout);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws HubException
     */
    private function send(string $method, string $path, string $raw, array $headers, int $timeout = self::TIMEOUT): HubResponse
    {
        $request = $this->request()->timeout($timeout)->withHeaders($headers);
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
