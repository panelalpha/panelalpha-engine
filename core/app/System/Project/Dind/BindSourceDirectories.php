<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\UncreatedBindDirectories;
use App\System\Project\Dind as DindProject;

/**
 * Creates, as the account, the checkout directories the compose file binds with `create_host_path: false`
 * ({@see UncreatedBindDirectories}) before `compose up`, which would otherwise refuse the missing source.
 */
final class BindSourceDirectories
{
    private const TIMEOUT_SECONDS = 60;

    /**
     * $1 the checkout, then the paths. One that exists, or that resolves outside the checkout through a
     * committed symlink, is skipped; each one created is printed.
     */
    private const CREATE = 'root=$(realpath -e -- "$1") || exit 0; shift; for p; do '
        .'{ [ -e "$p" ] || [ -L "$p" ]; } && continue; r=$(realpath -m -- "$p") || continue; '
        .'case "$r" in "$root"/*) ;; *) continue ;; esac; '
        .'mkdir -p -- "$r" && printf "%s\n" "$r"; done';

    public function __construct(private DindProject $project) {}

    public function create(): void
    {
        $paths = $this->declared();
        if ($paths === []) {
            return;
        }
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        $logger = $this->project->shell()->logger();
        try {
            $created = $this->project->shell()->execAsUserQuiet(['sh', '-c', self::CREATE, 'sh', $projectDir, ...$paths], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $logger?->warn('Could not create the bind mount directories the compose file asks Docker not to create: '.trim($e->getMessage()));

            return;
        }
        $relative = self::relativeTo($projectDir, $created);
        if ($relative !== []) {
            $logger?->info('Created '.implode(', ', $relative).', which the compose file binds with create_host_path: false');
        }
    }

    /** @return list<string> */
    private function declared(): array
    {
        try {
            $compose = ComposeYaml::parse((string) $this->project->system()->filesystem()->fileGetContents(
                $this->project->userAppComposeFileToRun()
            ));
        } catch (\Throwable) {
            return [];
        }

        return is_array($compose)
            ? UncreatedBindDirectories::of($compose, $this->project->userAppDirPath(), $this->interpolationEnv())
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

    /** @return list<string> */
    private static function relativeTo(string $projectDir, string $created): array
    {
        return array_map(
            static fn (string $path): string => ltrim(substr($path, strlen($projectDir)), '/'),
            array_values(array_filter(array_map('trim', explode("\n", $created))))
        );
    }
}
