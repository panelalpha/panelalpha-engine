<?php

namespace App\Lib\Project;

use App\Exceptions\NotFoundException;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppPortServices;
use App\System\Project\PhpHosting;
use Illuminate\Support\Carbon;

/**
 * A project's PHP errors. Classic hosting writes them to a file in the
 * project's own log directory, outside the account's home; a dind app writes
 * them to its service's container output.
 */
final class PhpErrorLog
{
    public const FILE_NAME = 'php_error.log';

    private const TAIL_TIMEOUT = 60;

    /** Run as the account: `$1` the file, `$2` the line count. */
    private const TAIL_SCRIPT = 'p=$1; [ -f "$p" ] && [ ! -L "$p" ] && [ "$(stat -c %h -- "$p" 2>/dev/null)" = 1 ] && exec tail -n "$2" -- "$p"; exit 3';

    private function __construct(
        private readonly User $user,
        private readonly System $system,
    ) {
    }

    public static function for(User $user, System $system): self
    {
        return new self($user, $system);
    }

    /**
     * @return array{lines: list<string>, file: ?string, size: int, mtime: ?int, truncated: bool}
     */
    public function read(int $lines, ?string $since, ?string $domain): array
    {
        $runtime = $this->user->project($this->system)->runtime();
        if ($runtime instanceof Dind) {
            return $this->readDind($runtime, $lines, $since);
        }

        return $runtime instanceof PhpHosting ? $this->readFile($lines, $since, $domain) : self::nothing();
    }

    /**
     * A dind app has no engine-written error log: its PHP errors are lines of
     * the application service's container output.
     *
     * @return array{lines: list<string>, file: null, size: int, mtime: null, truncated: false}
     */
    private function readDind(Dind $project, int $lines, ?string $since): array
    {
        $service = $this->appService($project);
        if ($service === null) {
            return self::nothing();
        }

        $logs = $project->getServiceLogs(
            $service,
            $lines,
            $since === null ? null : Carbon::parse($since)->toRfc3339String(),
        );

        return ['lines' => $this->split($logs), 'file' => null, 'size' => strlen($logs), 'mtime' => null, 'truncated' => false];
    }

    /**
     * The service publishing the recorded app port, as the health sweep names
     * the application; the generated `app`, or the first service, when no port
     * is recorded.
     */
    private function appService(Dind $project): ?string
    {
        $publishing = AppPortServices::names($project) ?? [];
        if ($publishing !== []) {
            return in_array(GeneratedCompose::APP_SERVICE, $publishing, true)
                ? GeneratedCompose::APP_SERVICE
                : (string) $publishing[0];
        }

        $compose = ComposeYaml::parseFile($project->userAppComposeFileToRun());
        $services = is_array($compose['services'] ?? null) ? array_keys($compose['services']) : [];

        return match (true) {
            $services === [] => null,
            in_array(GeneratedCompose::APP_SERVICE, $services, true) => GeneratedCompose::APP_SERVICE,
            default => (string) $services[0],
        };
    }

    /**
     * @return array{lines: list<string>, file: ?string, size: int, mtime: ?int, truncated: bool}
     */
    private function readFile(int $lines, ?string $since, ?string $domain): array
    {
        if ($domain !== null) {
            $this->assertDomain($domain);
        }

        $path = $this->system->projectDirPath($this->user->username) . '/log/php/' . self::FILE_NAME;
        $stat = AccountFile::ownedRegular($path, $this->user->getUid());
        if ($stat === null) {
            return self::nothing();
        }

        $output = $this->tailAsAccount($path, $lines + 1);
        if ($output === null) {
            return self::nothing();
        }

        $found = $this->split($output);
        $truncated = count($found) > $lines;
        if ($truncated) {
            $found = array_slice($found, -$lines);
        }

        return [
            'lines' => $this->since($found, $since),
            'file' => self::FILE_NAME,
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'truncated' => $truncated,
        ];
    }

    /**
     * Reads as the account, so a link it planted is not followed with root's
     * privileges. The guard is repeated on the account's side because the file
     * can be swapped between the lstat above and this open.
     */
    private function tailAsAccount(string $path, int $lines): ?string
    {
        try {
            $account = $this->user->project($this->system)->fileManager();
            $command = $account->asAccount(['sh', '-c', self::TAIL_SCRIPT, 'sh', $path, (string) $lines]);
            $process = $this->system->runProcess($command, [], self::TAIL_TIMEOUT);
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function assertDomain(string $domain): void
    {
        $exists = $this->user->domains()->getQuery()->where('domain', $domain)->exists();
        if (!$exists) {
            throw new NotFoundException("Domain '{$domain}' not found for project '{$this->user->username}'.", Domain::class);
        }
    }

    /**
     * A line PHP wrote has no timestamp of its own when it is a stack frame;
     * it stays, because nothing says when it was written.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function since(array $lines, ?string $since): array
    {
        if ($since === null) {
            return $lines;
        }

        $threshold = Carbon::parse($since)->getTimestamp();
        $kept = [];
        foreach ($lines as $line) {
            $stamp = self::timestampOf($line);
            if ($stamp === null || $stamp >= $threshold) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    private static function timestampOf(string $line): ?int
    {
        if (preg_match('/^\[(\d{1,2})-([A-Za-z]{3})-(\d{4}) (\d{1,2}):(\d{2}):(\d{2})/', $line, $m) !== 1) {
            return null;
        }

        $stamp = \DateTimeImmutable::createFromFormat(
            '!d-M-Y H:i:s',
            "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}",
            new \DateTimeZone('UTC'),
        );

        return $stamp === false ? null : $stamp->getTimestamp();
    }

    /**
     * @return list<string>
     */
    private function split(?string $output): array
    {
        if ($output === null) {
            return [];
        }

        $trimmed = rtrim($output, "\n");

        return $trimmed === '' ? [] : explode("\n", $trimmed);
    }

    /**
     * @return array{lines: list<string>, file: null, size: 0, mtime: null, truncated: false}
     */
    private static function nothing(): array
    {
        return ['lines' => [], 'file' => null, 'size' => 0, 'mtime' => null, 'truncated' => false];
    }
}
