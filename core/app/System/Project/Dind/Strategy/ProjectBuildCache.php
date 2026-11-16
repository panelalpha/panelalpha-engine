<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\BuildCache;
use App\System\Project\Dind as DindProject;

/**
 * The host-built {@see BuildCache} a deploy imports for each thing it builds; only the host writes one, from an
 * anonymous clone, for the branch the deploy recorded, else the checkout's.
 */
final class ProjectBuildCache
{
    private ?BuildCache $registry = null;

    public function __construct(private readonly DindProject $dind) {}

    /**
     * `cache_from` entries for the build $identity names, empty when the
     * cache registry has none for this repository and branch.
     *
     * @return list<string>
     */
    public function cacheFrom(string $projectDir, string $identity): array
    {
        $ref = $this->ref($projectDir, $identity);
        if ($ref === null) {
            return [];
        }
        $logger = $this->dind->shell()->logger();
        $this->registry ??= new BuildCache;
        if (! $this->registry->exists($ref)) {
            $logger?->dim("No build cache {$ref} in the cache registry");

            return [];
        }
        $logger?->info("Using build cache {$ref} from the cache registry");

        return [BuildCache::cacheFrom($ref)];
    }

    private function ref(string $projectDir, string $identity): ?string
    {
        $user = $this->dind->userModel();
        $repository = $user->getGitRepo();
        if ($repository === null) {
            return null;
        }
        $branch = $user->getGitBranch()
            ?? self::checkedOutBranch($this->dind->projectTree()->readIn($projectDir, '.git/HEAD'));

        return BuildCache::ref($repository, $branch, $identity);
    }

    /** `main` from `.git/HEAD`'s `ref: refs/heads/main`; null for a detached or missing HEAD. */
    public static function checkedOutBranch(?string $head): ?string
    {
        return preg_match('#^ref: refs/heads/(\S+)\s*$#', (string) $head, $m) === 1 ? $m[1] : null;
    }
}
