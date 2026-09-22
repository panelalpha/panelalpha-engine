<?php

namespace App\Lib\Deploy\Health;

/**
 * What the application actually answered: the status, and enough of the body to
 * recognise it by. The sample is capped at 4096 bytes, which covers a
 * placeholder or debug page's opening `<title>` and a health endpoint's JSON.
 */
final class ProbedResponse
{
    /** Bytes of the body the probe brings back. */
    public const SAMPLE_BYTES = 4096;

    /** Decoded once, on first ask. */
    private ?array $json = null;

    private bool $jsonRead = false;

    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $url,
        public readonly float $time = 0.0
    ) {
    }

    public static function none(string $url = ''): self
    {
        return new self(0, '', $url);
    }

    /**
     * Answered faster than the given number of seconds, with a time actually
     * measured. A proxy 502 with no upstream comes back in ~2ms; no
     * application-generated 5xx does, which is how the two are told apart.
     */
    public function respondedWithin(float $seconds): bool
    {
        return $this->time > 0.0 && $this->time < $seconds;
    }

    /** Whether anything answered at all. */
    public function answered(): bool
    {
        return $this->status > 0;
    }

    public function bodyContains(string $needle): bool
    {
        return $needle !== '' && str_contains($this->body, $needle);
    }

    /**
     * The body as a JSON object, or null when it is not one. Decoded as an
     * array; a JSON list is refused, since a check asks about members.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->jsonRead) {
            return $this->json;
        }
        $this->jsonRead = true;

        if ($this->body === '') {
            return $this->json = null;
        }

        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->json = null;
        }

        return $this->json = is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /** Could this body be read as a JSON object at all? */
    public function isJson(): bool
    {
        return $this->json() !== null;
    }

    /**
     * Does the status match one of `200`, `404`, `2xx`, `5xx`?
     *
     * @param list<int|string> $wanted
     */
    public function statusMatches(array $wanted): bool
    {
        foreach ($wanted as $one) {
            if (is_int($one) && $this->status === $one) {
                return true;
            }
            if (is_string($one) && preg_match('/^([1-5])xx$/', $one, $m) === 1
                && intdiv($this->status, 100) === (int) $m[1]) {
                return true;
            }
        }

        return false;
    }
}
