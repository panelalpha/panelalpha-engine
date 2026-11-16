<?php

namespace App\System\Project\Git;

/**
 * The `ff` pull strategy, `git merge --ff-only` after a fetch: git alone judges the tree, and its refusal leaves
 * the checkout as it was, so the caller must not run its backup restore. Users provide `git(array, ?string)`.
 */
trait FastForwardPull
{
    /** How many conflicting paths a message names before summarising the rest. */
    private const int FF_MAX_NAMED_PATHS = 20;

    /**
     * @throws FastForwardRefused 422 when git refuses (diverged history, or local changes
     *                            at paths the incoming commits change)
     * @throws Exception the original failure when it refuses for a reason not classified here
     */
    private function fastForwardOnly(string $branch): void
    {
        $ref = 'origin/'.$branch;

        try {
            $this->git(['merge', '--ff-only', $ref]);
        } catch (Exception $refusal) {
            throw $this->explainRefusedFastForward($ref, $refusal);
        }
    }

    /** A checkout with no commits yet has no history to have diverged from. */
    private function explainRefusedFastForward(string $ref, Exception $refusal): Exception
    {
        try {
            $this->git(['rev-parse', '--verify', '--end-of-options', $ref]);
        } catch (Exception) {
            return new Exception('Branch does not exist on remote.', 422);
        }

        $hasHead = $this->headCommitExists();
        if ($hasHead && ! $this->headIsAncestorOf($ref)) {
            return new FastForwardRefused(
                'Cannot fast-forward: the local branch and '.$ref.' have diverged '
                .'(the remote history was rewritten, or this checkout has commits the remote does not). '
                .'Nothing was changed. Use strategy `force` to reset to the remote, '
                .'or `push_first` to merge and push.',
            );
        }

        $blocking = $this->pathsBlockingFastForward($ref, $hasHead);
        if ($blocking !== []) {
            $named = array_slice($blocking, 0, self::FF_MAX_NAMED_PATHS);
            $more = count($blocking) - count($named);

            return new FastForwardRefused(
                'Cannot fast-forward: the pull would overwrite local changes at: '
                .implode(', ', $named).($more > 0 ? ' and '.$more.' more' : '')
                .'. Nothing was changed. Commit, stash or remove them, or use strategy `force`.',
                $blocking,
            );
        }

        return $refusal;
    }

    private function headIsAncestorOf(string $ref): bool
    {
        try {
            $this->git(['merge-base', '--is-ancestor', 'HEAD', $ref]);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Incoming paths that collide with something local, so the message can name them: the same path, or a
     * file where the other needs a directory.
     *
     * @return list<string>
     */
    private function pathsBlockingFastForward(string $ref, bool $hasHead): array
    {
        $incoming = $this->incomingPaths($ref, $hasHead);
        if ($incoming === []) {
            return [];
        }

        $local = $this->localPaths($hasHead);

        $localPaths = [];
        $localDirs = [];
        foreach ($local as $path) {
            $localPaths[$path] = true;
            foreach ($this->parentDirectories($path) as $dir) {
                $localDirs[$dir] = true;
            }
        }

        $blocking = [];
        foreach ($incoming as $path) {
            if (isset($localPaths[$path]) || isset($localDirs[$path]) || $this->hasLocalFileAbove($path, $localPaths)) {
                $blocking[] = $path;
            }
        }

        return $blocking;
    }

    /**
     * The paths $ref changes; without a HEAD there is nothing to diff against, so everything in it.
     *
     * @return list<string>
     */
    private function incomingPaths(string $ref, bool $hasHead): array
    {
        return $hasHead
            ? $this->nulSeparated(['diff', '--name-only', '--no-renames', '-z', 'HEAD', $ref])
            : $this->nulSeparated(['ls-tree', '-r', '--name-only', '-z', $ref]);
    }

    /**
     * Tracked paths modified against HEAD, staged or not, or the whole index without a HEAD, then untracked ones.
     *
     * @return list<string>
     */
    private function localPaths(bool $hasHead): array
    {
        return [
            ...($hasHead
                ? $this->nulSeparated(['diff', '--name-only', '--no-renames', '-z', 'HEAD'])
                : $this->nulSeparated(['ls-files', '-z'])),
            ...$this->nulSeparated(['ls-files', '--others', '--exclude-standard', '-z']),
        ];
    }

    /**
     * True when a local path sits where `$incoming` needs a directory
     * (`a` is a local file, the incoming commit adds `a/b`).
     *
     * @param  array<string, true>  $localPaths
     */
    private function hasLocalFileAbove(string $incoming, array $localPaths): bool
    {
        foreach ($this->parentDirectories($incoming) as $dir) {
            if (isset($localPaths[$dir])) {
                return true;
            }
        }

        return false;
    }

    /**
     * `a/b/c.php` yields `a/b`, then `a`.
     *
     * @return \Generator<int, string>
     */
    private function parentDirectories(string $path): \Generator
    {
        while (($slash = strrpos($path, '/')) !== false && $slash > 0) {
            $path = substr($path, 0, $slash);
            yield $path;
        }
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function nulSeparated(array $args): array
    {
        return array_values(array_filter(explode("\0", $this->git($args)), static fn (string $p): bool => $p !== ''));
    }
}
