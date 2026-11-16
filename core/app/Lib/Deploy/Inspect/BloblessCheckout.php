<?php

namespace App\Lib\Deploy\Inspect;

use Symfony\Component\Process\Process;

/**
 * A bare blobless clone written out as GitHubTree writes a file list: every file of HEAD empty except what
 * {@see DetectionFiles} reads, fetched in at most two requests. Anything inexact is a TreeUnavailable.
 */
final class BloblessCheckout
{
    /** Git never fetches a missing object on its own here: every blob read is one fetched by name. */
    private const LOCAL_ONLY_ENV = [
        'GIT_NO_LAZY_FETCH' => '1',
        'GIT_TERMINAL_PROMPT' => '0',
        'LC_ALL' => 'C',
        'LANG' => 'C',
    ];

    public function __construct(private readonly string $gitDir, private readonly int $timeout) {}

    /**
     * @return array{commit: ?string, branch: ?string}
     */
    public function head(): array
    {
        return [
            'commit' => $this->readOrNull(['rev-parse', 'HEAD']),
            'branch' => $this->readOrNull(['symbolic-ref', '--short', 'HEAD']),
        ];
    }

    /**
     * Write HEAD into $dir.
     *
     * @param  \Closure(list<string>): void  $fetch  fetches these blob ids from the remote
     *
     * @throws TreeUnavailable
     */
    public function writeTo(string $dir, ?string $subdirectory, \Closure $fetch): void
    {
        [$entries, $ids] = $this->tree();
        $files = DetectionFiles::select($entries, $subdirectory);
        $contents = $this->fetched($files, $ids, $fetch);
        $listed = DetectionFiles::solutionListed(
            $entries,
            $files,
            $subdirectory,
            static fn (string $path): ?string => $contents[$path] ?? null
        );
        if ($listed !== []) {
            $files['decisive'] = [...$files['decisive'], ...$listed];
            $contents = $this->fetched($files, $ids, $fetch);
        }

        GitHubTree::skeleton($dir, $entries);
        $this->write($dir, $files, $contents);
    }

    /**
     * The contents of the files to read, fetching the blobs not held yet in one request.
     *
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files
     * @param  array<string, string>  $ids  path => blob id
     * @param  \Closure(list<string>): void  $fetch
     * @return array<string, string> path => contents
     *
     * @throws TreeUnavailable
     */
    private function fetched(array $files, array $ids, \Closure $fetch): array
    {
        $wanted = self::wanted($files, $ids);
        $this->fetchMissing($wanted, $fetch);

        return $this->contents($wanted, $this->readable($this->sizes($wanted), $files));
    }

    /**
     * The blob ids to read, decisive files and links first and refining files with what is left of the budget.
     *
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files
     * @param  array<string, string>  $ids  path => blob id
     * @return array<string, string>
     *
     * @throws TreeUnavailable
     */
    private static function wanted(array $files, array $ids): array
    {
        $required = [...$files['decisive'], ...$files['links']];
        if (count($required) > DetectionFiles::MAX_FILES) {
            throw new TreeUnavailable('More files to read than a partial clone fetches.');
        }
        $refining = array_slice($files['refining'], 0, DetectionFiles::MAX_FILES - count($required));

        return array_intersect_key($ids, array_flip([...$required, ...$refining]));
    }

    /**
     * A server that ignores the filter has sent every blob already, and nothing is fetched.
     *
     * @param  array<string, string>  $wanted
     * @param  \Closure(list<string>): void  $fetch
     *
     * @throws TreeUnavailable
     */
    private function fetchMissing(array $wanted, \Closure $fetch): void
    {
        $missing = array_filter($this->sizes($wanted), static fn (?int $size): bool => $size === null);
        if ($missing !== []) {
            $fetch(array_values(array_unique(array_intersect_key($wanted, $missing))));
        }
    }

    /**
     * Every entry of HEAD, and the blob id of each file.
     *
     * @return array{0: list<array{path: string, type: string, mode: string, size: ?int}>, 1: array<string, string>}
     *
     * @throws TreeUnavailable
     */
    private function tree(): array
    {
        $items = [];
        $ids = [];
        foreach (explode("\0", $this->read(['ls-tree', '-r', '-t', '-z', '--full-tree', 'HEAD'])) as $record) {
            if (preg_match('/^(\d+) (\w+) ([0-9a-f]+)\t(.+)$/s', $record, $m) !== 1) {
                continue;
            }
            $items[] = ['path' => $m[4], 'type' => $m[2], 'mode' => $m[1]];
            if ($m[2] === 'blob') {
                $ids[$m[4]] = $m[3];
            }
        }

        return [GitHubTree::entries($items), $ids];
    }

    /**
     * The size of each blob held locally, null for one not fetched yet.
     *
     * @param  array<string, string>  $ids  path => blob id
     * @return array<string, ?int> path => size
     *
     * @throws TreeUnavailable
     */
    private function sizes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sizes = [];
        foreach (explode("\n", $this->read(['cat-file', '--batch-check'], self::lines($ids))) as $line) {
            $parts = explode(' ', $line);
            if (count($parts) === 3 && $parts[1] === 'blob') {
                $sizes[$parts[0]] = (int) $parts[2];
            }
        }

        return array_map(static fn (string $id): ?int => $sizes[$id] ?? null, $ids);
    }

    /**
     * The paths whose contents are read: every decisive file and link must be there, and a decisive file no larger
     * than detection reads; anything else too large or not fetched stays empty.
     *
     * @param  array<string, ?int>  $sizes
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files
     * @return list<string>
     *
     * @throws TreeUnavailable
     */
    private function readable(array $sizes, array $files): array
    {
        foreach ([...$files['decisive'], ...$files['links']] as $path) {
            if (($sizes[$path] ?? null) === null) {
                throw new TreeUnavailable("Could not fetch {$path}.");
            }
        }
        foreach ($files['decisive'] as $path) {
            if ($sizes[$path] > DetectionFiles::MAX_BYTES) {
                throw new TreeUnavailable("{$path} is too large to fetch.");
            }
        }

        return array_keys(array_filter(
            $sizes,
            static fn (?int $size): bool => $size !== null && $size <= DetectionFiles::MAX_BYTES
        ));
    }

    /**
     * @param  array<string, string>  $ids  path => blob id
     * @param  list<string>  $paths
     * @return array<string, string> path => contents
     *
     * @throws TreeUnavailable
     */
    private function contents(array $ids, array $paths): array
    {
        $ids = array_intersect_key($ids, array_flip($paths));
        if ($ids === []) {
            return [];
        }
        $objects = self::parseBatch($this->read(['cat-file', '--batch'], self::lines($ids)));

        return array_map(static fn (string $id): string => $objects[$id] ?? '', $ids);
    }

    /**
     * `cat-file --batch` output as blob id => contents; a missing object has no body and is left out.
     *
     * @return array<string, string>
     */
    public static function parseBatch(string $output): array
    {
        $objects = [];
        $offset = 0;
        while (($eol = strpos($output, "\n", $offset)) !== false) {
            $header = explode(' ', substr($output, $offset, $eol - $offset));
            $offset = $eol + 1;
            if (count($header) !== 3) {
                continue;
            }
            $size = (int) $header[2];
            $objects[$header[0]] = substr($output, $offset, $size);
            $offset += $size + 1;
        }

        return $objects;
    }

    /**
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files
     * @param  array<string, string>  $contents
     *
     * @throws TreeUnavailable
     */
    private function write(string $dir, array $files, array $contents): void
    {
        foreach ([...$files['decisive'], ...$files['refining']] as $path) {
            if (isset($contents[$path]) && @file_put_contents($dir.'/'.$path, $contents[$path]) === false) {
                throw new TreeUnavailable("Could not write {$path}.");
            }
        }
        foreach ($files['links'] as $path) {
            if (isset($contents[$path])) {
                GitHubTree::placeLink($dir, $path, $contents[$path]);
            }
        }
    }

    /**
     * @param  array<string, string>  $ids
     */
    private static function lines(array $ids): string
    {
        return implode("\n", array_unique(array_values($ids)))."\n";
    }

    /**
     * @param  list<string>  $args
     */
    private function readOrNull(array $args): ?string
    {
        try {
            $out = trim($this->read($args));
        } catch (TreeUnavailable) {
            return null;
        }

        return $out === '' ? null : $out;
    }

    /**
     * @param  list<string>  $args
     *
     * @throws TreeUnavailable
     */
    private function read(array $args, ?string $input = null): string
    {
        $process = new Process(
            ['git', '-c', 'safe.directory=*', '--git-dir='.$this->gitDir, ...$args],
            null,
            self::LOCAL_ONLY_ENV,
            $input,
            $this->timeout
        );
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new TreeUnavailable('git '.$args[0].' failed: '.$e->getMessage(), 0, $e);
        }
        if (! $process->isSuccessful()) {
            throw new TreeUnavailable('git '.$args[0].' failed.');
        }

        return $process->getOutput();
    }
}
