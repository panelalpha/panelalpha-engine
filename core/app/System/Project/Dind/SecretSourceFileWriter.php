<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\SecretSourceFiles;
use App\System\Project\Dind as DindProject;

/**
 * Writes, as the account, each secret file the compose file reads that the repository leaves out, with the
 * account's generated value ({@see SecretSourceFiles}), before `compose up` refuses the missing file.
 */
final class SecretSourceFileWriter
{
    private const TIMEOUT_SECONDS = 60;

    /**
     * $1 the checkout, then destination and value pairs. A destination that exists, or resolves outside the
     * checkout through a committed symlink, is skipped; each one written is printed.
     */
    private const WRITE = 'root=$(realpath -e -- "$1") || exit 0; shift; umask 022; while [ $# -ge 2 ]; do d=$1; v=$2; shift 2; '
        .'{ [ -e "$d" ] || [ -L "$d" ]; } && continue; rd=$(realpath -m -- "$d") || continue; '
        .'case "$rd" in "$root"/*) ;; *) continue ;; esac; '
        .'mkdir -p -- "$(dirname -- "$rd")" && printf "%s" "$v" > "$rd" && printf "%s\n" "$rd"; done';

    public function __construct(private DindProject $project) {}

    public function create(): void
    {
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        $missing = $this->missing($projectDir);
        if ($missing === []) {
            return;
        }
        $seed = $this->project->strategy()->secrets()->for('compose-placeholders');
        $pairs = [];
        foreach ($missing as $relative => $secret) {
            array_push($pairs, $projectDir.'/'.$relative, SecretSourceFiles::value($secret, $seed));
        }
        $logger = $this->project->shell()->logger();
        try {
            $written = $this->project->shell()->execAsUserQuiet(['sh', '-c', self::WRITE, 'sh', $projectDir, ...$pairs], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $logger?->warn('Could not create the secret files the compose file reads: '.trim($e->getMessage()));

            return;
        }
        foreach (array_filter(array_map('trim', explode("\n", $written))) as $path) {
            $relative = ltrim(substr($path, strlen($projectDir)), '/');
            $logger?->info("Created {$relative} with a generated secret: the compose file reads it and the repository leaves it out");
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
            ? SecretSourceFiles::missing($compose, static fn (string $relative): bool => $fs->fileExists($projectDir.'/'.$relative))
            : [];
    }
}
