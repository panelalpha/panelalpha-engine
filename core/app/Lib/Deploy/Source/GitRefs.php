<?php

namespace App\Lib\Deploy\Source;

/**
 * The branches and tags a remote advertised, read from `git ls-remote --symref`.
 *
 * `$defaultBranch` is the branch HEAD pointed at, when the remote named one.
 */
final class GitRefs
{
    /**
     * @param list<string> $branches
     * @param list<string> $tags
     */
    private function __construct(
        public readonly ?string $defaultBranch,
        public readonly array $branches,
        public readonly array $tags,
    ) {
    }

    public static function parse(string $output): self
    {
        $branches = [];
        $tags = [];
        $default = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('#^ref: refs/heads/(\S+)\s+HEAD$#', $line, $m) === 1) {
                $default = $m[1];
            } elseif (preg_match('#^[0-9a-f]{40,64}\s+refs/heads/(\S+)$#', $line, $m) === 1) {
                $branches[] = $m[1];
            } elseif (preg_match('#^[0-9a-f]{40,64}\s+refs/tags/(\S+?)(\^\{\})?$#', $line, $m) === 1) {
                $tags[] = $m[1];
            }
        }

        return new self($default, array_values(array_unique($branches)), array_values(array_unique($tags)));
    }

    public function hasBranch(string $branch): bool
    {
        return in_array($branch, $this->branches, true) || in_array($branch, $this->tags, true);
    }

    /**
     * The default branch first, then the rest by name.
     *
     * @return list<string>
     */
    public function sortedBranches(): array
    {
        $branches = $this->branches;
        sort($branches, SORT_STRING);

        if ($this->defaultBranch === null || !in_array($this->defaultBranch, $branches, true)) {
            return $branches;
        }

        return [$this->defaultBranch, ...array_values(array_diff($branches, [$this->defaultBranch]))];
    }

    /** @return list<string> */
    public function sortedTags(): array
    {
        $tags = $this->tags;
        sort($tags, SORT_STRING);

        return $tags;
    }
}
