<?php

namespace App\Lib\Domains;

use App\Exceptions\NotFoundException;
use App\Models\Domain;
use App\Models\User;
use App\System\Project\Domain as ProjectDomain;

/** A project domain's webserver log files, as the API and the CLI both read them. */
final class DomainLogFiles
{
    private function __construct(private readonly ProjectDomain $domain, private readonly string $name)
    {
    }

    /** Null when the project has no such domain. */
    public static function find(User $user, string $domain): ?self
    {
        /** @var ?Domain $model */
        $model = $user->domains()->getQuery()->where('domain', $domain)->first();

        return $model === null ? null : new self($model->projectDomain(), $model->domain);
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
}
