<?php

namespace App\Lib\Domains;

use App\Exceptions\NotFoundException;
use App\Lib\Helpers\FileStreamWrapper;
use App\Lib\Project\PhpErrorLog;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Domain as ProjectDomain;

/** A project domain's webserver log files, as the API and the CLI both read them. */
final class DomainLogFiles
{
    private function __construct(private readonly ProjectDomain $domain, private readonly string $name)
    {
    }

    /** Null when the project has no such domain. */
    public static function find(User $user, string $domain, ?System $system = null): ?self
    {
        /** @var ?Domain $model */
        $model = $user->domains()->getQuery()->where('domain', $domain)->first();
        if ($model === null) {
            return null;
        }

        return new self($user->project($system ?? new System())->domain($model), $model->domain);
    }

    public static function findOrFail(User $user, string $domain): self
    {
        return self::find($user, $domain)
            ?? throw new NotFoundException("Domain '{$domain}' not found for project '{$user->username}'.");
    }

    /**
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function list(bool $allWebservers): array
    {
        return $allWebservers ? $this->domain->listWebserverLogFiles() : $this->domain->listLogFiles();
    }

    public function path(string $filename, bool $allWebservers): ?string
    {
        foreach ($this->list($allWebservers) as $logFile) {
            if ($logFile['file'] == $filename) {
                return $logFile['path'];
            }
        }

        return null;
    }

    public function pathOrFail(string $filename, bool $allWebservers): string
    {
        return $this->path($filename, $allWebservers)
            ?? throw new NotFoundException("Log file '{$filename}' not found for domain '{$this->name}'.");
    }

    /**
     * A path the API can open, or null when the file is not there. The php
     * error log lives in a directory the account owns, so it is opened as the
     * account and confined to that directory; a link the account plants is
     * refused rather than followed with the engine's privileges.
     */
    public function readablePath(string $filename, bool $allWebservers): ?string
    {
        $path = $this->path($filename, $allWebservers);
        if ($path === null) {
            return null;
        }

        if ($filename !== PhpErrorLog::FILE_NAME) {
            return is_readable($path) ? $path : null;
        }

        $files = $this->domain->project()->fileManager();
        FileStreamWrapper::register();
        FileStreamWrapper::confineTo(dirname($path), $files->asAccount([]));

        return 'sudophp://' . $path;
    }

    public function readablePathOrFail(string $filename, bool $allWebservers): string
    {
        return $this->readablePath($filename, $allWebservers)
            ?? throw new NotFoundException("Log file '{$filename}' not found for domain '{$this->name}'.");
    }
}
