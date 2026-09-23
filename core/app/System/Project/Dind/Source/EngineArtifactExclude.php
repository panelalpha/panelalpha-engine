<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\Checkout\CheckoutExclude;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Env\EnvExampleCopies;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the engine's block in a git checkout's local exclude file — ADR-0001.
 *
 * The block lists every reserved Engine Artifact name, plus the files a deploy
 * writes only when missing that exist now and that the repository does not
 * track. With them excluded, `git status` shows only the client's changes and
 * a forced pull's `clean -fd` leaves the generated `.env` in place.
 */
final class EngineArtifactExclude
{
    /**
     * @param Closure(string $path, string $contents): void $writeFile
     */
    public function __construct(
        private GitRepository $git,
        private Closure $writeFile,
    ) {
    }

    public static function forProject(DindProject $dind): self
    {
        $chown = $dind->userModel()->getChownString();

        return new self(
            new GitRepository($dind),
            static function (string $path, string $contents) use ($dind, $chown): void {
                $dind->system()->filesystem()->filePutContents($path, $contents, $chown, '644');
            },
        );
    }

    /**
     * Rewrite the block. Does nothing outside a git checkout, and never fails a
     * deploy: a checkout without the block only shows engine files as
     * untracked, which is how it was before.
     */
    public function write(): void
    {
        try {
            $path = $this->git->localExcludePath();
            if ($path === null) {
                return;
            }

            $existing = is_file($path) ? (string) file_get_contents($path) : '';
            $patterns = [...EngineArtifacts::reservedPatterns(), ...$this->untrackedGeneratedPatterns()];
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
     * @return list<string>
     */
    private function untrackedGeneratedPatterns(): array
    {
        $generated = $this->generatedFiles();
        $untracked = array_values(array_diff($generated, $this->git->trackedAmong($generated)));

        return array_map(CheckoutExclude::anchoredPath(...), $untracked);
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
            if (is_file($checkout . '/' . $relative)) {
                $files[] = $relative;
            }
        }
        if (PlaceholderPage::isOneOf($checkout . '/index.html')) {
            $files[] = 'index.html';
        }

        return array_values(array_unique([...$files, ...EnvExampleCopies::made($checkout)]));
    }
}
