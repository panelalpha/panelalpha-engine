<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\Checkout\CheckoutExclude;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Checkout\InstallLockfiles;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Env\EnvExampleCopies;
use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the engine's block (reserved Engine Artifacts, and untracked files a deploy writes or installs) in a checkout's
 * local exclude file, so `git status` shows only the client's changes and `clean -fd` keeps `.env` (ADR-0001).
 */
final class EngineArtifactExclude
{
    /**
     * @param  Closure(string $path, string $contents): void  $writeFile
     */
    public function __construct(
        private GitRepository $git,
        private Closure $writeFile,
    ) {}

    public static function forProject(DindProject $dind): self
    {
        $chown = $dind->userModel()->getChownString();

        return new self(
            new GitRepository($dind),
            static function (string $path, string $contents) use ($dind, $chown): void {
                $dind->system()->filesystem()->writeFileReplacingPath($path, $contents, $chown, '644');
            },
        );
    }

    /**
     * Rewrite the block; nothing outside a git checkout, and never fails a deploy, since a checkout without the
     * block only shows engine files as untracked.
     */
    public function write(): void
    {
        try {
            $path = $this->git->localExcludePath();
            if ($path === null) {
                return;
            }

            $existing = is_file($path) ? (string) file_get_contents($path) : '';
            $patterns = [
                ...EngineArtifacts::reservedPatterns(),
                ...$this->untrackedGeneratedPatterns(),
                ...$this->untrackedInstallLockfiles(),
                ...$this->untrackedInstallFolders(),
            ];
            $merged = CheckoutExclude::merge($existing, CheckoutExclude::render($patterns));
            if ($merged !== $existing) {
                ($this->writeFile)($path, $merged);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not update the Engine Artifact exclude block', [
                'checkout' => $this->git->absolutePath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A checkout a deploy rebuilds from, git_repo or git_connect: a push's
     * forced pull runs `clean -fd`, which takes every unlisted artifact with it.
     */
    public function writeForDeploy(): void
    {
        if ($this->git->isDeployManaged()) {
            $this->write();
        }
    }

    /**
     * @return list<string>
     */
    private function untrackedGeneratedPatterns(): array
    {
        return array_map(
            CheckoutExclude::anchoredPath(...),
            $this->untrackedAmong($this->generatedFiles())
        );
    }

    /**
     * The lockfile an install generates because the repository ships none, which the build writes after this block is
     * written: a repository that tracks one owns it, and keeps it.
     *
     * @return list<string>
     */
    private function untrackedInstallLockfiles(): array
    {
        return array_map(
            CheckoutExclude::anchoredPath(...),
            $this->untrackedAmong(InstallLockfiles::candidates($this->git->absolutePath()))
        );
    }

    /**
     * The folders the deploy's own install writes the dependencies into, which no repository writes itself: without
     * them a repository that ignores none shows every deploy as the client's change, and no branch can be changed.
     * A folder the repository tracks a file in is the repository's, and stays out.
     *
     * @return list<string>
     */
    private function untrackedInstallFolders(): array
    {
        $folders = InstallLockfiles::dependencyFolders($this->git->absolutePath());
        $tracked = $this->git->trackedAmong($folders);
        $untracked = array_filter($folders, static fn (string $folder): bool => ! self::holdsAny($folder, $tracked));

        return array_map(CheckoutExclude::anchoredPath(...), array_values($untracked));
    }

    /** @param  list<string>  $tracked */
    private static function holdsAny(string $folder, array $tracked): bool
    {
        foreach ($tracked as $path) {
            if ($path === $folder || str_starts_with($path, $folder.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function untrackedAmong(array $paths): array
    {
        return array_values(array_diff($paths, $this->git->trackedAmong($paths)));
    }

    /**
     * Checkout-relative paths of files the engine writes only when missing,
     * which exist now.
     *
     * @return list<string>
     */
    private function generatedFiles(): array
    {
        $checkout = $this->git->absolutePath();
        $candidates = ['.env', '.dockerignore', EntrypointWriter::PROJECT_OVERRIDE];

        $files = [];
        foreach ($candidates as $relative) {
            if (is_file($checkout.'/'.$relative)) {
                $files[] = $relative;
            }
        }
        if (PlaceholderPage::isOneOf($checkout.'/index.html')) {
            $files[] = 'index.html';
        }

        return array_values(array_unique([
            ...$files,
            ...$this->engineWrittenDockerfileIgnores($checkout),
            ...EnvExampleCopies::made($checkout),
        ]));
    }

    /**
     * The `<Dockerfile>.dockerignore` files the engine wrote next to a project's own Dockerfile.
     *
     * @return list<string>
     */
    private function engineWrittenDockerfileIgnores(string $checkout): array
    {
        $files = [];
        foreach (['*', '*/*', '*/*/*'] as $depth) {
            foreach (glob($checkout.'/'.$depth.'.dockerignore') ?: [] as $path) {
                $head = is_file($path) ? (string) file_get_contents($path, false, null, 0, 256) : null;
                if (BuildContextIgnore::isEngineWritten($head)) {
                    $files[] = substr($path, strlen($checkout) + 1);
                }
            }
        }

        return $files;
    }
}
