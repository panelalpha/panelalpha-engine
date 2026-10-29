<?php

namespace App\System\Services\Mysql;

/**
 * What a MySQL copy produced: the source→destination names and the fresh
 * credentials. Held in memory for the next step of the copy only; a password
 * here is never written to a task, a log or an API response.
 */
final class DatabaseCopyResult
{
    /**
     * @param  array<string, string>  $databases  source database => destination database
     * @param  array<string, string>  $users  source user => destination user
     * @param  array<string, string>  $passwords  destination user => password
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $databases = [],
        public readonly array $users = [],
        public readonly array $passwords = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->databases, $this->users, $this->passwords, [...$this->warnings, ...$warnings]);
    }

    /**
     * The copied databases as the `[{source, dest}]` a task details block carries.
     *
     * @return list<array{source: string, dest: string}>
     */
    public function databasePairs(): array
    {
        $pairs = [];
        foreach ($this->databases as $source => $dest) {
            $pairs[] = ['source' => $source, 'dest' => $dest];
        }

        return $pairs;
    }
}
