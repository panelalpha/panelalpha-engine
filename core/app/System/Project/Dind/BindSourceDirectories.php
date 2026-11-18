<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\UncreatedBindDirectories;
use App\System\Project\Dind as DindProject;

/**
 * Creates, as the account, the checkout directories the compose file binds with `create_host_path: false`
 * ({@see UncreatedBindDirectories}) before `compose up`, which would otherwise refuse the missing source,
 * and those {@see BindSourceOwners} hands to a non-root service, which Docker would create as root.
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
        $relative = $this->createMissing($this->declared());
        if ($relative !== []) {
            $this->project->shell()->logger()?->info('Created '.implode(', ', $relative).', which the compose file binds with create_host_path: false');
        }
    }

    /**
     * Each of $paths that does not exist yet, created as the account when it resolves inside the checkout;
     * the ones created, relative to it.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function createMissing(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        try {
            $created = $this->project->shell()->execAsUserQuiet(['sh', '-c', self::CREATE, 'sh', $projectDir, ...$paths], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $this->project->shell()->logger()?->warn('Could not create the bind mount directories the compose file names: '.trim($e->getMessage()));

            return [];
        }

        return self::relativeTo($projectDir, $created);
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
