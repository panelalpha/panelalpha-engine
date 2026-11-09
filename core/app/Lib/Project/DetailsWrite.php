<?php

namespace App\Lib\Project;

use Closure;

/**
 * A project's details are one document a deploy job, the CLI and the API all write. A save puts the keys it changed,
 * entry by entry for collections, onto the stored document, so what another process wrote since stays.
 */
final class DetailsWrite
{
    /**
     * @param  Closure(string): ?string  $plaintext  a stored secret's plaintext; null for a value that is none, or unreadable
     * @param  Closure(array<array-key, mixed>): string  $seal  a document as stored encrypted
     * @param  list<string>  $collections  keys whose entries different writers change independently
     */
    public function __construct(
        private readonly Closure $plaintext,
        private readonly Closure $seal,
        private readonly array $collections = [],
    ) {}

    /**
     * @param  array<string, mixed>  $stored  the document as stored now
     * @param  array<string, mixed>  $read  as this process read it
     * @param  array<string, mixed>  $written  as this process would store it
     * @return array<string, mixed>
     */
    public function onto(array $stored, array $read, array $written): array
    {
        foreach ($this->changes($read, $written) as $key => $changed) {
            if (! $changed) {
                unset($stored[$key]);
            } elseif (in_array($key, $this->collections, true) && array_key_exists($key, $stored)) {
                $stored[$key] = $this->entriesOnto($stored[$key], $read[$key] ?? [], $written[$key]);
            } else {
                $stored[$key] = $written[$key];
            }
        }

        return $stored;
    }

    /**
     * The keys this process changed: true for one it set, false for one it removed.
     *
     * @param  array<array-key, mixed>  $read
     * @param  array<array-key, mixed>  $written
     * @return array<array-key, bool>
     */
    private function changes(array $read, array $written): array
    {
        $changes = [];
        foreach (array_keys($read + $written) as $key) {
            if (! array_key_exists($key, $written)) {
                $changes[$key] = false;
            } elseif (! array_key_exists($key, $read) || ! $this->same($read[$key], $written[$key])) {
                $changes[$key] = true;
            }
        }

        return $changes;
    }

    /** The entries this process changed, onto the collection as stored; the whole of it when a side cannot be read. */
    private function entriesOnto(mixed $stored, mixed $read, mixed $written): mixed
    {
        $storedEntries = $this->entries($stored);
        $readEntries = $this->entries($read);
        $writtenEntries = $this->entries($written);
        if ($storedEntries === null || $readEntries === null || $writtenEntries === null) {
            return $written;
        }
        foreach ($this->changes($readEntries, $writtenEntries) as $entry => $changed) {
            if ($changed) {
                $storedEntries[$entry] = $writtenEntries[$entry];
            } else {
                unset($storedEntries[$entry]);
            }
        }

        return is_string($written) ? ($this->seal)($storedEntries) : $storedEntries;
    }

    /**
     * A collection as an array, decrypted when it is stored encrypted; null when it cannot be read.
     *
     * @return ?array<array-key, mixed>
     */
    private function entries(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        $plaintext = is_string($value) ? ($this->plaintext)($value) : null;
        $entries = $plaintext === null ? null : json_decode($plaintext, true);

        return is_array($entries) ? $entries : null;
    }

    /** A secret encrypted again is the same value; one that cannot be read is the same only as itself. */
    private function same(mixed $read, mixed $written): bool
    {
        if ($read === $written) {
            return true;
        }
        if (is_array($read) && is_array($written)) {
            return $this->sameEntries($read, $written);
        }
        if (! is_string($read) || ! is_string($written)) {
            return false;
        }
        $plaintext = ($this->plaintext)($read);

        return $plaintext !== null && $plaintext === ($this->plaintext)($written);
    }

    /**
     * @param  array<array-key, mixed>  $read
     * @param  array<array-key, mixed>  $written
     */
    private function sameEntries(array $read, array $written): bool
    {
        if (count($read) !== count($written)) {
            return false;
        }
        foreach ($read as $key => $value) {
            if (! array_key_exists($key, $written) || ! $this->same($value, $written[$key])) {
                return false;
            }
        }

        return true;
    }
}
