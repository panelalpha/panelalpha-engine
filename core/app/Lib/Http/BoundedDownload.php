<?php

namespace App\Lib\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * One HTTP GET written to a file, refused once the body passes the byte cap.
 *
 * The cap is read from the response's Content-Length before the body arrives
 * and counted again while the body streams, so an endless or chunked answer is
 * not written to disk in full before anybody can refuse it. Redirects are left
 * to the caller, which checks every hop against OutboundUrl.
 */
final class BoundedDownload
{
    /** A slow release archive still fits; the plain sink waited this long too. */
    private const TIMEOUT_SECONDS = 600;

    private bool $exceeded = false;

    public function __construct(
        private readonly int $maxBytes = OutboundUrl::MAX_DOWNLOAD_BYTES,
    ) {}

    /**
     * @param  ?string  $pin  a `host:port:address` value from OutboundUrl::pin()
     *
     * @throws InvalidArgumentException when the body is larger than the cap
     */
    public function fetch(string $url, string $into, ?string $pin = null): Response
    {
        $this->exceeded = false;

        try {
            return Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions($this->options($into, $pin))
                ->get($url);
        } catch (ConnectionException $e) {
            if ($this->exceeded) {
                throw $this->tooLarge($url);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $into, ?string $pin): array
    {
        $options = [
            'sink' => $into,
            'allow_redirects' => false,
            'on_headers' => fn ($response) => $this->refuseWhen((int) $response->getHeaderLine('Content-Length') > $this->maxBytes),
            'progress' => fn ($total, $downloaded) => $this->refuseWhen($downloaded > $this->maxBytes),
        ];

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        return $options;
    }

    private function refuseWhen(bool $overLimit): void
    {
        if (! $overLimit) {
            return;
        }

        $this->exceeded = true;

        throw new InvalidArgumentException('The download is larger than the '.intdiv($this->maxBytes, 1048576).' MB limit.');
    }

    private function tooLarge(string $url): InvalidArgumentException
    {
        return new InvalidArgumentException("The file at {$url} is larger than the ".intdiv($this->maxBytes, 1048576).' MB limit.');
    }
}
