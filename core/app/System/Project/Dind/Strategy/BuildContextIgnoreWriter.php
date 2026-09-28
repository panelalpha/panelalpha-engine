<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\Lib\Deploy\Platform\Dockerfile\DockerIgnore;
use App\Lib\Deploy\Platform\Dockerfile\GitHistoryUse;
use App\System\Project\Dind as DindProject;

/**
 * Writes `<Dockerfile>.dockerignore` for every image the engine builds from
 * the checkout, so the engine's files and `.git` stay out of the context.
 * {@see BuildContextIgnore}. For a repository Dockerfile it runs after the
 * environment is applied ({@see DockerfileStrategy::keepEngineFilesOutOfContext()}).
 */
class BuildContextIgnoreWriter
{
    public function __construct(private DindProject $dind)
    {
    }

    /**
     * @param string $dockerfile relative to $projectDir, which is the build context
     * @param bool   $generated  the engine wrote this Dockerfile
     */
    public function write(string $projectDir, string $dockerfile, bool $generated, ?string $chown): void
    {
        $tree = $this->dind->projectTree();
        $logger = $this->dind->shell()->logger();
        $target = BuildContextIgnore::pathFor($dockerfile);

        $existing = $tree->readIn($projectDir, $target);
        if ($existing !== null && !BuildContextIgnore::isEngineWritten($existing)) {
            $logger?->info("Using the project's own {$target} for the build context");

            return;
        }

        $projectIgnore = $tree->readIn($projectDir, DockerIgnore::FILENAME);
        $manifests = [];
        foreach (GitHistoryUse::manifestFiles() as $file) {
            $manifests[$file] = $tree->readIn($projectDir, $file);
        }
        $reason = GitHistoryUse::reason(
            $generated ? null : $tree->readIn($projectDir, $dockerfile),
            $projectIgnore,
            $manifests
        );

        $excludeEnv = !$generated && $this->emptyEnvIsUnread($projectDir, $dockerfile);

        $this->dind->system()->filesystem()->filePutContents(
            rtrim($projectDir, '/') . '/' . $target,
            BuildContextIgnore::render($projectIgnore, $dockerfile, $reason === null, $generated, $excludeEnv),
            $chown,
            '644'
        );

        $engineFiles = $excludeEnv ? "the engine's compose files and the empty .env" : "the engine's compose files";
        $logger?->info($reason === null
            ? "Build context: .git and {$engineFiles} left out ({$target})"
            : "Build context: .git kept because {$reason}, so the build cannot reuse its cache "
                . "past `COPY . .`; {$engineFiles} left out ({$target})");
    }

    /**
     * A repository Dockerfile only: its ignore file is written once the
     * environment is applied, so `.env` holds what the build will see.
     * {@see BuildContextIgnore::excludesEnv()}.
     */
    private function emptyEnvIsUnread(string $projectDir, string $dockerfile): bool
    {
        $tree = $this->dind->projectTree();
        $envPath = rtrim($projectDir, '/') . '/.env';
        // readIn() reads an empty file as null, the same as a missing one.
        $env = $this->dind->system()->filesystem()->fileExists($envPath) ? ($tree->read($envPath) ?? '') : null;

        return BuildContextIgnore::excludesEnv($env, $tree->readIn($projectDir, $dockerfile));
    }
}
