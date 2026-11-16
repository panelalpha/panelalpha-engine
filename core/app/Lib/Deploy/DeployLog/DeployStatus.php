<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Findings\Finding;

/**
 * `latest.json`: which deploy an account is on, how far it got and whether it is still going. Written whole
 * and renamed into place, because a polling request reads it while the deploy writes it.
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
     * A finished deploy's warnings. A partial deploy recorded before warnings had codes said them in `error`.
     *
     * @param  array<string, mixed>  $latest
     * @return list<Finding>
     */
    public static function warningsOf(array $latest): array
    {
        if (array_key_exists('warnings', $latest)) {
            return Finding::allStored($latest['warnings']);
        }

        return self::isUncodedPartial($latest) ? Finding::allStored(explode(' | ', $latest['error'])) : [];
    }

    /**
     * Why a failed deploy failed, with its code; a deploy recorded before failures had one said it in `error` alone.
     *
     * @param  array<string, mixed>  $latest
     */
    public static function failureOf(array $latest): ?Finding
    {
        if (($latest['status'] ?? null) !== DeployLogger::STATUS_FAILED) {
            return null;
        }

        return Finding::fromStored($latest['failure'] ?? null) ?? Finding::fromStored($latest['error'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    public static function errorOf(array $latest): ?string
    {
        $error = $latest['error'] ?? null;

        return is_string($error) && ! self::isUncodedPartial($latest) ? $error : null;
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private static function isUncodedPartial(array $latest): bool
    {
        return ($latest['status'] ?? null) === DeployLogger::STATUS_PARTIAL
            && ! array_key_exists('warnings', $latest)
            && is_string($latest['error'] ?? null);
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
