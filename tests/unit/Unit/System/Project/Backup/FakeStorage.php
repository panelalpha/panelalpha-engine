<?php

namespace Tests\Unit\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;

final class FakeStorage implements BackupStorage
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $putKeys = [];

    /** @var list<string> */
    public array $deletePrefixCalls = [];

    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $readStreamOverrides = [];

    private int $putCount = 0;

    private int $deletePrefixCount = 0;

    public function __construct(
        private ?int $failPutOn = null,
        private string $failureMessage = 'upload failed',
        private ?string $deletePrefixFailureMessage = null,
        private ?int $failDeletePrefixOn = null,
    ) {
    }

    public function put(string $key, $stream): void
    {
        $this->calls[] = 'put';
        $this->putCount++;
        if ($this->failPutOn !== null && $this->putCount === $this->failPutOn) {
            throw new \RuntimeException($this->failureMessage);
        }

        $this->putKeys[] = $key;
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read upload stream.');
        }
        $this->objects[$key] = $contents;
    }

    /** @return resource */
    public function readStream(string $key)
    {
        $this->calls[] = 'readStream:' . $key;
        $contents = $this->readStreamOverrides[$key] ?? ($this->objects[$key] ?? null);
        if ($contents === null) {
            throw new \RuntimeException('Object not found: ' . $key);
        }

        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open memory stream.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->deletePrefixCalls[] = $prefix;
        $this->deletePrefixCount++;
        if ($this->deletePrefixFailureMessage !== null) {
            $shouldFail = $this->failDeletePrefixOn === null
                || $this->deletePrefixCount === $this->failDeletePrefixOn;
            if ($shouldFail) {
                throw new \RuntimeException($this->deletePrefixFailureMessage);
            }
        }

        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->objects[$key]);
            }
        }
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->objects);
    }

    public function size(string $key): int
    {
        return strlen($this->objects[$key] ?? '');
    }

    public function test(): void
    {
    }
}
