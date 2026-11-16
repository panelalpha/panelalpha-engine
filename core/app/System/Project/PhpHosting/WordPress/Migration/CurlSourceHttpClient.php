<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Support\JobBudget;
use Closure;
use RuntimeException;

/**
 * cURL with in-memory cookies (never on the record or the job), verified TLS and one checked redirect hop at a time.
 * Each request is pinned (CURLOPT_RESOLVE) to the checked address or `source_ip`, so curl never resolves the host again.
 */
final class CurlSourceHttpClient implements SourceHttpClient
{
    private const MAX_REDIRECTS = 5;

    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /** Long enough for the source plugin to scan a whole site, short of the job's own timeout. */
    public const REQUEST_TIMEOUT_SECONDS = JobBudget::COMMAND_SECONDS;

    /** Per request of the connection test, which holds an HTTP request and its PHP-FPM worker while it waits. */
    public const CONNECTION_TEST_TIMEOUT_SECONDS = 20;

    private const CONNECTION_TEST_CONNECT_TIMEOUT_SECONDS = 10;

    private \CurlHandle $handle;

    /** @var (callable(string): list<string>)|null */
    private $resolve;

    /** @var (Closure(): bool)|null */
    private ?Closure $keepWaiting = null;

    private SourceIpPolicy $policy;

    /**
     * @param  (callable(string): list<string>)|null  $resolve  DNS lookup; tests pass a fixture
     */
    public function __construct(
        private readonly ?SourceIpPin $pin = null,
        ?callable $resolve = null,
        ?SourceIpPolicy $policy = null,
    ) {
        $this->resolve = $resolve;
        $this->policy = $policy ?? app(SourceIpPolicy::class);
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Could not initialize cURL for source WordPress client');
        }
        $this->handle = $handle;
        curl_setopt($this->handle, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($this->handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($this->handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($this->handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($this->handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($this->handle, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        curl_setopt($this->handle, CURLOPT_NOPROGRESS, false);
        curl_setopt($this->handle, CURLOPT_XFERINFOFUNCTION, $this->transferProgress(...));
        curl_setopt($this->handle, CURLOPT_HEADER, true);
        curl_setopt($this->handle, CURLINFO_HEADER_OUT, true);
        curl_setopt($this->handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    }

    /**
     * A client for the connection test: each request gives up after $seconds,
     * not after the queue job's budget, because an HTTP request waits on it.
     *
     * @param  (callable(string): list<string>)|null  $resolve  DNS lookup; tests pass a fixture
     */
    public static function forConnectionTest(
        ?SourceIpPin $pin = null,
        ?callable $resolve = null,
        int $seconds = self::CONNECTION_TEST_TIMEOUT_SECONDS,
    ): self {
        $client = new self($pin, $resolve);
        curl_setopt($client->handle, CURLOPT_CONNECTTIMEOUT, min($seconds, self::CONNECTION_TEST_CONNECT_TIMEOUT_SECONDS));
        curl_setopt($client->handle, CURLOPT_TIMEOUT, $seconds);

        return $client;
    }

    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
    ): SourceHttpResponse {
        return $this->send($method, $url, $body, self::headerLines($headers));
    }

    public function postMultipart(string $url, array $fields): SourceHttpResponse
    {
        return $this->send('POST', $url, $fields, []);
    }

    public function whileWaiting(?Closure $keepWaiting): void
    {
        $this->keepWaiting = $keepWaiting;
    }

    /** curl's progress callback, called about once a second: a non-zero answer aborts the transfer. */
    private function transferProgress(): int
    {
        return $this->shouldKeepWaiting() ? 0 : 1;
    }

    private function shouldKeepWaiting(): bool
    {
        return $this->keepWaiting === null || ($this->keepWaiting)();
    }

    /**
     * @param  string|array<string, mixed>|null  $body
     * @param  list<string>  $headerLines
     */
    private function send(string $method, string $url, string|array|null $body, array $headerLines): SourceHttpResponse
    {
        $currentMethod = strtoupper($method);
        $currentUrl = $url;
        $currentBody = $body;
        $currentHeaders = $headerLines;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! $this->shouldKeepWaiting()) {
                throw SourceRequestAborted::at($currentUrl);
            }
            $raw = $this->exchange($currentMethod, $currentUrl, $currentBody, $currentHeaders);
            $redirects = $raw['status'] >= 300 && $raw['status'] < 400 && $raw['location'] !== null;
            if (! $redirects) {
                return new SourceHttpResponse($raw['body'], $raw['status'], $raw['effective']);
            }
            if ($hop === self::MAX_REDIRECTS) {
                throw new RuntimeException('Source WordPress URL redirected too many times');
            }

            [$currentMethod, $currentUrl, $currentBody, $currentHeaders] = self::nextHop($raw, $currentMethod, $currentUrl, $currentBody, $currentHeaders);
        }

        throw new RuntimeException('Source WordPress URL redirected too many times');
    }

    /**
     * @param  string|array<string, mixed>|null  $body
     * @param  list<string>  $headerLines
     * @return array{body: string, status: int, effective: ?string, location: ?string}
     */
    private function exchange(string $method, string $url, string|array|null $body, array $headerLines): array
    {
        curl_setopt($this->handle, CURLOPT_RESOLVE, $this->resolveEntries($url));
        curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->handle, CURLOPT_URL, $url);
        curl_setopt($this->handle, CURLOPT_REFERER, $url);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($this->handle, CURLOPT_POSTFIELDS, $body);

        return $this->execRaw($url);
    }

    /**
     * @param  array{body: string, status: int, effective: ?string, location: string}  $raw
     * @param  string|array<string, mixed>|null  $body
     * @param  list<string>  $headerLines
     * @return array{0: string, 1: string, 2: string|array<string, mixed>|null, 3: list<string>}
     */
    private static function nextHop(array $raw, string $method, string $url, string|array|null $body, array $headerLines): array
    {
        $next = SourceFetchGuard::resolveRedirect($url, $raw['location']);
        SourceFetchGuard::assertRedirectAllowed($raw['status'], $url, $next);
        if (in_array($raw['status'], SourceFetchGuard::REDIRECTS_AS_GET, true)) {
            $method = 'GET';
            $body = null;
            $headerLines = [];
        }

        return [$method, $next, $body, $headerLines];
    }

    /**
     * CURLOPT_RESOLVE entries for a request: the host on ports 80, 443 and its
     * own, at the pinned or the checked address. Old entries for the host go first.
     *
     * @return list<string>
     */
    public function resolveEntries(string $url): array
    {
        $host = SourceFetchGuard::assertShape($url);
        $address = $this->addressFor($url, $host);
        if ($address === null) {
            return [];
        }

        $target = str_contains($address, ':') ? '['.$address.']' : $address;
        $entries = [];
        foreach (self::ports($url) as $port) {
            $entries[] = '-'.$host.':'.$port;
            $entries[] = $host.':'.$port.':'.$target;
        }

        return $entries;
    }

    private function addressFor(string $url, string $host): ?string
    {
        if ($this->pin !== null && $this->pin->covers($host)) {
            SourceFetchGuard::assertAddressAllowed($this->pin->ip, $this->policy);

            return $this->pin->ip;
        }

        $addresses = SourceFetchGuard::assertAllowed($url, $this->policy, $this->resolve);

        return filter_var($host, FILTER_VALIDATE_IP) === false ? $addresses[0] : null;
    }

    /**
     * @return list<int>
     */
    private static function ports(string $url): array
    {
        $port = parse_url($url, PHP_URL_PORT);

        return array_values(array_unique(array_filter([80, 443, is_int($port) ? $port : null])));
    }

    /**
     * @return array{body: string, status: int, effective: ?string, location: ?string}
     */
    private function execRaw(string $url): array
    {
        $output = curl_exec($this->handle);
        if ($output === false) {
            throw $this->requestFailure($url);
        }

        /** @var array{http_code?: int, header_size?: int, url?: string} $info */
        $info = curl_getinfo($this->handle);
        $headerSize = (int) ($info['header_size'] ?? 0);
        $raw = (string) $output;
        $header = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $status = (int) ($info['http_code'] ?? 0);
        $effective = isset($info['url']) && is_string($info['url']) ? $info['url'] : null;

        return [
            'body' => $body,
            'status' => $status,
            'effective' => $effective,
            'location' => self::locationHeader($header),
        ];
    }

    private function requestFailure(string $url): RuntimeException
    {
        if (curl_errno($this->handle) === CURLE_ABORTED_BY_CALLBACK) {
            return SourceRequestAborted::at($url);
        }

        $error = curl_error($this->handle);
        $message = 'Source WordPress HTTP request failed'
            .($error !== '' ? ': '.$error : '')
            .' ('.$url.')';

        if (in_array(curl_errno($this->handle), self::TLS_ERRORS, true)) {
            return SourceCheckFailed::tls($message);
        }

        return SourceCheckFailed::unreachable($message);
    }

    private static function locationHeader(string $rawHeaders): ?string
    {
        $location = null;
        foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
            if (preg_match('/^Location:\s*(.*)$/i', $line, $matches) === 1) {
                $location = trim($matches[1]);
            }
        }

        return $location === null || $location === '' ? null : $location;
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return $lines;
    }
}
