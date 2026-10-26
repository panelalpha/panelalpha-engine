<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * `latest.json`: which deploy an account is on, how far it got, and whether
 * it is still going.
 *
 * A separate polling request reads this file while the deploy request is
 * still writing it, so every update is written whole and renamed into place.
 */
final class DeployStatus
{
    public function __construct(private readonly DeployLogPaths $paths) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $path = $this->paths->latest();
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(array $data): void
    {
        LogStorage::ensureDirectory($this->paths->directory());
        LogStorage::replace($this->paths->latest(), (string) json_encode($data));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the stored record
     */
    public function update(array $changes): array
    {
        $latest = array_merge($this->read() ?? [], $changes);
        $this->write($latest);

        return $latest;
    }

    public function value(string $key): mixed
    {
        return $this->read()[$key] ?? null;
    }

    public function is(string $status): bool
    {
        return $this->value('status') === $status;
    }

    /**
     * @return array<string, mixed> a fresh record for a deploy about to run
     */
    public static function started(string $deployId): array
    {
        return [
            'id' => $deployId,
            'status' => DeployLogger::STATUS_RUNNING,
            'stage' => null,
            'pid' => null,
            'started_at' => time(),
            'finished_at' => null,
            'error' => null,
            'stages' => [],
        ];
    }

    /**
     * Close the open stage, if any, and name it.
     *
     * @param  list<array{name: string, started_at: ?int, finished_at: ?int}>  $stages
     * @return array{0: list<array<string, mixed>>, 1: ?string} stages, closed stage name
     */
    public static function closeOpenStage(array $stages, int $now): array
    {
        $last = array_key_last($stages);
        if ($last === null || $stages[$last]['finished_at'] !== null) {
            return [$stages, null];
        }
        $stages[$last]['finished_at'] = $now;

        return [$stages, $stages[$last]['name']];
    }
}
