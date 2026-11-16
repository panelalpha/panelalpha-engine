<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Source\GitTransport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * A public GitHub repository written out for inspection without a clone: one trees request, every file empty,
 * and only what {@see DetectionFiles} reads fetched from raw.githubusercontent.com. Anything inexact is a TreeUnavailable.
 */
final class GitHubTree
{
    private const API = 'https://api.github.com';

    private const RAW = 'https://raw.githubusercontent.com';

    /** Seconds for each request. */
    private const TIMEOUT = 20;

    /** Raw files fetched at once. */
    private const CONCURRENCY = 16;

    private const USER_AGENT = 'PanelAlpha-Engine';

    /** When writeTo() gives up, with a budget. */
    private ?float $deadline = null;

    /**
     * @param  ?\Closure(string, ?string): array{0: string, 1: ?string}  $head
     *                                                                          the commit and branch a ref names; `git ls-remote` when null
     * @param  ?float  $budget  seconds the whole of writeTo() may take; null
     *                          bounds each request only
     */
    public function __construct(private readonly ?\Closure $head = null, private readonly ?float $budget = null) {}

    /**
     * Owner and name of a github.com HTTPS repository URL with nothing else in
     * it, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function repository(string $repoUrl): ?array
    {
        $parts = parse_url(trim($repoUrl));
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'github.com'
            || isset($parts['user'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $parts['path'] ?? '', $m) !== 1
        ) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Write $repoUrl at $branch (the default branch when null) into $dir.
     *
     * @return array{repository: string, branch: ?string, commit: ?string}
     *
     * @throws TreeUnavailable
     */
    public function writeTo(string $dir, string $repoUrl, ?string $branch = null, ?string $subdirectory = null): array
    {
        $repository = self::repository($repoUrl);
        if ($repository === null) {
            throw new TreeUnavailable('Not a github.com repository URL.');
        }
        [$owner, $name] = $repository;
        $this->deadline = $this->budget === null ? null : microtime(true) + $this->budget;

        [$commit, $resolvedBranch] = $this->resolveHead($repoUrl, $branch);
        $ref = $commit ?? ($branch !== null && $branch !== '' ? $branch : 'HEAD');
        $entries = $this->tree($owner, $name, $ref);

        $files = DetectionFiles::select($entries, $subdirectory);
        $sizes = self::blobSizes($entries);
        $decisive = $files['decisive'];
        $links = $files['links'];
        self::assertFetchable($decisive, $links, $sizes);

        self::skeleton($dir, $entries);
        $base = self::RAW.'/'.rawurlencode($owner).'/'.rawurlencode($name).'/'.self::encodePath($ref).'/';
        $this->fetch($base, $dir, $decisive, $sizes, true);
        $this->link($base, $dir, $links, $sizes);
        $listed = $this->fetchSolutionListed($base, $dir, $entries, $files, $subdirectory, $sizes);
        $budget = DetectionFiles::MAX_FILES - count($decisive) - count($links) - count($listed);
        $this->fetchRefining($base, $dir, $files['refining'], $sizes, $budget);

        return [
            'repository' => $repoUrl,
            'branch' => $branch !== null && $branch !== '' ? $branch : $resolvedBranch,
            'commit' => $commit,
        ];
    }

    /**
     * The commit and branch $branch names, or none when git is refused: GitHub throttles unauthenticated
     * git downloads, which fails the clone too, while the API and the raw files still answer by name.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveHead(string $repoUrl, ?string $branch): array
    {
        try {
            [$commit, $resolvedBranch] = $this->head !== null
                ? ($this->head)($repoUrl, $branch)
                : self::lsRemote($repoUrl, $branch, $this->timeout());
        } catch (TreeUnavailable) {
            [$commit, $resolvedBranch] = [null, null];
        }

        return [$commit, $resolvedBranch];
    }

    /**
     * @param  list<array{path: string, type: string, mode: string, size: ?int}>  $entries
     * @return array<string, int>
     */
    private static function blobSizes(array $entries): array
    {
        $sizes = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'blob') {
                $sizes[$entry['path']] = (int) ($entry['size'] ?? 0);
            }
        }

        return $sizes;
    }

    /**
     * @param  list<string>  $decisive
     * @param  list<string>  $links
     * @param  array<string, int>  $sizes
     *
     * @throws TreeUnavailable
     */
    private static function assertFetchable(array $decisive, array $links, array $sizes): void
    {
        if (count($decisive) + count($links) > DetectionFiles::MAX_FILES) {
            throw new TreeUnavailable('More files to read than a tree inspection fetches.');
        }
        foreach ($decisive as $path) {
            if ($sizes[$path] > DetectionFiles::MAX_BYTES) {
                throw new TreeUnavailable("{$path} is too large to fetch.");
            }
        }
    }

    /**
     * The second round: the projects the fetched root solutions list beyond the first one's reach, and the
     * Directory.Build.props above them, within its budget.
     *
     * @param  list<array{path: string, type: string, mode: string, size: ?int}>  $entries
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files
     * @param  array<string, int>  $sizes
     * @return list<string> the paths fetched
     *
     * @throws TreeUnavailable
     */
    private function fetchSolutionListed(
        string $base,
        string $dir,
        array $entries,
        array $files,
        ?string $subdirectory,
        array $sizes
    ): array {
        $listed = DetectionFiles::solutionListed($entries, $files, $subdirectory, static function (string $path) use ($dir): ?string {
            $contents = AccountHomeRead::contents($dir.'/'.$path);

            return is_string($contents) ? $contents : null;
        });
        self::assertFetchable([...$files['decisive'], ...$listed], $files['links'], $sizes);
        $this->fetch($base, $dir, $listed, $sizes, true);

        return $listed;
    }

    /**
     * What is left of the file budget goes to the files that refine a build command.
     *
     * @param  list<string>  $refining
     * @param  array<string, int>  $sizes
     */
    private function fetchRefining(string $base, string $dir, array $refining, array $sizes, int $budget): void
    {
        $refining = array_values(array_filter(
            $refining,
            static fn (string $p): bool => $sizes[$p] <= DetectionFiles::MAX_BYTES
        ));
        $this->fetch($base, $dir, array_slice($refining, 0, max(0, $budget)), $sizes, false);
    }

    /**
     * Every directory and file of the tree under $dir, files empty, and an empty .git where a clone has
     * its own: a Dockerfile that copies it (`COPY .git/ ...`) is only usable when it is there.
     *
     * @param  list<array{path: string, type: string, mode: string, size: ?int}>  $entries
     *
     * @throws TreeUnavailable
     */
    public static function skeleton(string $dir, array $entries): void
    {
        if (! is_dir($dir.'/.git') && ! @mkdir($dir.'/.git', 0755, true)) {
            throw new TreeUnavailable('Could not create the inspection workspace.');
        }
        foreach ($entries as $entry) {
            $path = $dir.'/'.$entry['path'];
            if ($entry['type'] === 'tree') {
                if (! is_dir($path) && ! @mkdir($path, 0755, true)) {
                    throw new TreeUnavailable("Could not create {$entry['path']}.");
                }

                continue;
            }
            $parent = dirname($path);
            if (! is_dir($parent) && ! @mkdir($parent, 0755, true)) {
                throw new TreeUnavailable("Could not create {$entry['path']}.");
            }
            if (@file_put_contents($path, '') === false) {
                throw new TreeUnavailable("Could not create {$entry['path']}.");
            }
        }
    }

    /**
     * The recursive tree at $ref, checked for anything that would make the written copy differ from a clone.
     * 403 and 429 are the rate limit, 404 a private or missing repository.
     *
     * @return list<array{path: string, type: string, mode: string, size: ?int}>
     *
     * @throws TreeUnavailable
     */
    private function tree(string $owner, string $name, string $ref): array
    {
        $url = self::API.'/repos/'.rawurlencode($owner).'/'.rawurlencode($name).'/git/trees/'.rawurlencode($ref);
        try {
            $response = $this->client()->accept('application/vnd.github+json')->get($url, ['recursive' => '1']);
        } catch (\Throwable $e) {
            throw new TreeUnavailable('GitHub could not be reached: '.$e->getMessage(), 0, $e);
        }
        if ($response->status() !== 200) {
            throw new TreeUnavailable("GitHub answered {$response->status()} for the file list.");
        }
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['tree'] ?? null)) {
            throw new TreeUnavailable('GitHub returned no file list.');
        }
        if (($body['truncated'] ?? false) === true) {
            throw new TreeUnavailable('The file list is truncated.');
        }

        return self::entries($body['tree']);
    }

    /**
     * Each item as an entry; a path that cannot be written, or a submodule the clone would check out, is refused.
     *
     * @param  array<mixed>  $tree
     * @return list<array{path: string, type: string, mode: string, size: ?int}>
     *
     * @throws TreeUnavailable
     */
    public static function entries(array $tree): array
    {
        $entries = [];
        foreach ($tree as $item) {
            $path = is_array($item) ? ($item['path'] ?? null) : null;
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if (! is_string($path) || ! self::safePath($path) || ! in_array($type, ['blob', 'tree', 'commit'], true)) {
                throw new TreeUnavailable('The file list holds an entry that cannot be written.');
            }
            if ($type === 'commit') {
                throw new TreeUnavailable('The repository has submodules.');
            }
            $entries[] = [
                'path' => $path,
                'type' => $type,
                'mode' => (string) ($item['mode'] ?? ''),
                'size' => isset($item['size']) ? (int) $item['size'] : null,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, int>  $sizes
     *
     * @throws TreeUnavailable when $strict and a file did not arrive whole
     */
    private function fetch(string $base, string $dir, array $paths, array $sizes, bool $strict): void
    {
        foreach ($this->download($base, $paths, $sizes, $strict) as $path => $body) {
            if (@file_put_contents($dir.'/'.$path, $body) === false) {
                throw new TreeUnavailable("Could not write {$path}.");
            }
        }
    }

    /**
     * The files that arrived whole, by path; the tree states every blob's size, so a short read is caught.
     *
     * @param  list<string>  $paths
     * @param  array<string, int>  $sizes
     * @return array<string, string>
     *
     * @throws TreeUnavailable when $strict and one did not
     */
    private function download(string $base, array $paths, array $sizes, bool $strict): array
    {
        if ($paths === []) {
            return [];
        }
        $timeout = $this->timeout();
        $responses = Http::pool(static function (Pool $pool) use ($paths, $base, $timeout): array {
            $requests = [];
            foreach ($paths as $path) {
                $requests[] = $pool->as($path)
                    ->withUserAgent(self::USER_AGENT)
                    ->timeout($timeout)
                    ->get($base.self::encodePath($path));
            }

            return $requests;
        }, self::CONCURRENCY);

        $bodies = [];
        foreach ($paths as $path) {
            $response = $responses[$path] ?? null;
            $body = $response instanceof Response && $response->status() === 200 ? $response->body() : null;
            if ($body === null || strlen($body) !== $sizes[$path]) {
                if ($strict) {
                    throw new TreeUnavailable("Could not fetch {$path}.");
                }

                continue;
            }
            $bodies[$path] = $body;
        }

        return $bodies;
    }

    /**
     * Symbolic links become links again, as a checkout makes them, when they
     * point inside the repository. Any other stays an empty file.
     *
     * @param  list<string>  $paths
     * @param  array<string, int>  $sizes
     *
     * @throws TreeUnavailable
     */
    private function link(string $base, string $dir, array $paths, array $sizes): void
    {
        foreach ($this->download($base, $paths, $sizes, true) as $path => $target) {
            self::placeLink($dir, $path, $target);
        }
    }

    /**
     * The empty file at $path becomes a link to $target when that lands inside the repository.
     *
     * @throws TreeUnavailable
     */
    public static function placeLink(string $dir, string $path, string $target): void
    {
        if (self::resolveLink($path, $target) === null) {
            return;
        }
        $link = $dir.'/'.$path;
        if (! @unlink($link) || ! @symlink($target, $link)) {
            throw new TreeUnavailable("Could not write {$path}.");
        }
    }

    /** Where a link at $path to $target lands, relative to the root; null outside it. */
    public static function resolveLink(string $path, string $target): ?string
    {
        if ($target === '' || str_starts_with($target, '/') || str_contains($target, "\0")) {
            return null;
        }
        $parts = explode('/', $path);
        array_pop($parts);
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function safePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout($this->timeout());
    }

    /**
     * Seconds the next request may take: TIMEOUT, or what is left of the
     * budget when that is less.
     *
     * @throws TreeUnavailable once the budget is spent
     */
    private function timeout(): float
    {
        if ($this->deadline === null) {
            return self::TIMEOUT;
        }
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw new TreeUnavailable('Reading the file list took longer than '.$this->budget.' s.');
        }

        return min(self::TIMEOUT, $left);
    }

    /**
     * The commit $branch names, and the default branch when it is null, over git's own protocol, which the API
     * rate limit does not count. Run outside any checkout: ls-remote reads the config of the one it runs in.
     *
     * @return array{0: string, 1: ?string}
     *
     * @throws TreeUnavailable
     */
    public static function lsRemote(string $repoUrl, ?string $branch, float $timeout = self::TIMEOUT): array
    {
        $refs = $branch === null || $branch === ''
            ? ['HEAD']
            : ['refs/heads/'.$branch, 'refs/tags/'.$branch, 'refs/tags/'.$branch.'^{}'];
        $process = new Process(
            ['git', '-c', 'credential.helper=', '-c', 'core.askpass=', 'ls-remote', '--symref', '--', $repoUrl, ...$refs],
            sys_get_temp_dir(),
            [...GitTransport::env(GitTransport::schemes()), 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/bin/false', 'SSH_ASKPASS' => '/bin/false', 'LC_ALL' => 'C', 'LANG' => 'C'],
            null,
            $timeout
        );
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new TreeUnavailable('git ls-remote failed: '.$e->getMessage(), 0, $e);
        }
        if (! $process->isSuccessful()) {
            throw new TreeUnavailable('git ls-remote failed.');
        }

        return self::parseLsRemote($process->getOutput(), $branch);
    }

    /**
     * A peeled tag is preferred: it is the commit, where the bare one may be a tag object.
     *
     * @return array{0: string, 1: ?string}
     *
     * @throws TreeUnavailable
     */
    public static function parseLsRemote(string $output, ?string $branch): array
    {
        $shas = [];
        $default = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD$#', $line, $m) === 1) {
                $default = $m[1];
            } elseif (preg_match('#^([0-9a-f]{40})\s+(\S+)$#', $line, $m) === 1) {
                $shas[$m[2]] = $m[1];
            }
        }

        $wanted = $branch === null || $branch === ''
            ? ['HEAD']
            : ['refs/heads/'.$branch, 'refs/tags/'.$branch.'^{}', 'refs/tags/'.$branch];
        foreach ($wanted as $ref) {
            if (isset($shas[$ref])) {
                return [$shas[$ref], $default];
            }
        }

        throw new TreeUnavailable('The branch was not found.');
    }
}
