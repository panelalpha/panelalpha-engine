<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\MissingBindFiles;
use App\System\Project\Dind as DindProject;

/**
 * Creates, as the account, each file the compose file binds that the repository leaves out, from the example it ships
 * beside it ({@see MissingBindFiles}), before `compose up` would put a directory in its place.
 */
final class BindSourceExamples
{
    private const TIMEOUT_SECONDS = 60;

    /**
     * $1 the checkout, then example and destination pairs. A destination that exists, or either end resolving outside
     * the checkout through a committed symlink, is skipped; each one created is printed.
     */
    private const COPY = 'root=$(realpath -e -- "$1") || exit 0; shift; while [ $# -ge 2 ]; do e=$1; d=$2; shift 2; '
        .'{ [ -e "$d" ] || [ -L "$d" ]; } && continue; re=$(realpath -e -- "$e") || continue; rd=$(realpath -m -- "$d") || continue; '
        .'case "$re" in "$root"/*) ;; *) continue ;; esac; case "$rd" in "$root"/*) ;; *) continue ;; esac; '
        .'[ -f "$re" ] || continue; mkdir -p -- "$(dirname -- "$rd")" && cp -- "$re" "$rd" && printf "%s\n" "$rd"; done';

    public function __construct(private DindProject $project) {}

    public function create(): void
    {
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        $examples = $this->missing($projectDir);
        if ($examples === []) {
            return;
        }
        $pairs = [];
        foreach ($examples as $destination => $example) {
            array_push($pairs, $example, $destination);
        }
        $logger = $this->project->shell()->logger();
        try {
            $created = $this->project->shell()->execAsUserQuiet(['sh', '-c', self::COPY, 'sh', $projectDir, ...$pairs], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $logger?->warn('Could not create the files the compose file binds from their examples: '.trim($e->getMessage()));

            return;
        }
        foreach (array_filter(array_map('trim', explode("\n", $created))) as $path) {
            $relative = ltrim(substr($path, strlen($projectDir)), '/');
            $from = isset($examples[$path]) ? ltrim(substr($examples[$path], strlen($projectDir)), '/') : 'its example';
            $logger?->info("Created {$relative} from {$from}: the compose file binds it and the repository leaves it out");
        }
    }

    /** @return array<string, string> */
    private function missing(string $projectDir): array
    {
        $fs = $this->project->system()->filesystem();
        try {
            $compose = ComposeYaml::parse((string) $fs->fileGetContents($this->project->userAppComposeFileToRun()));
        } catch (\Throwable) {
            return [];
        }

        return is_array($compose)
            ? MissingBindFiles::examples($compose, $projectDir, static fn (string $path): bool => $fs->fileExists($path), $this->interpolationEnv())
            : [];
    }

    /** @return array<string, list<?string>|string> */
    private function interpolationEnv(): array
    {
        try {
            return $this->project->environment()->forInterpolation();
        } catch (\Throwable) {
            return [];
        }
    }
}
