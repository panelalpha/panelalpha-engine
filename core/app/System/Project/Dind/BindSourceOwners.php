<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\WritableProjectBinds;
use App\System\Project\Dind as DindProject;

/**
 * Hands checkout paths a non-root service writes through a bind mount to the
 * uid it runs as, before `compose up` ({@see WritableProjectBinds}).
 *
 * Owner becomes that uid, the group stays the account's with group write, so
 * the account keeps writing them too; nothing is widened to other uids (#173).
 */
final class BindSourceOwners
{
    private const TIMEOUT_SECONDS = 60;

    /**
     * Run as root in the account: $1 the checkout, $2 uid:gid, then the paths.
     * A path that resolves outside the checkout (a committed symlink to /usr)
     * is skipped, and nothing below it is followed (-h, -P).
     */
    private const HAND_OVER = 'root=$1; owner=$2; shift 2; for p; do '
        . '[ -L "$p" ] && continue; r=$(realpath -e -- "$p" 2>/dev/null) || continue; '
        . 'case "$r" in "$root"/*) ;; *) continue ;; esac; '
        . 'chown -R -h -P "$owner" -- "$r" && chmod -R g+w -- "$r" && printf "%s\n" "$r"; done';

    public function __construct(private DindProject $project)
    {
    }

    public function apply(): void
    {
        $model = $this->project->userModel();
        $accountUid = $model->getUid();
        $accountGid = $model->getGid();
        $logger = $this->project->shell()->logger();
        if ($accountUid === null || $accountGid === null) {
            return;
        }

        try {
            $compose = ComposeYaml::parse((string) $this->project->system()->filesystem()->fileGetContents(
                $this->project->userAppComposeFileToRun()
            ));
        } catch (\Throwable) {
            return;
        }
        if (!is_array($compose)) {
            return;
        }

        $fs = $this->project->system()->filesystem();
        foreach (WritableProjectBinds::of($compose, $this->project->userAppDirPath()) as $service => $bind) {
            // Directories too: keycloak's ./keycloak/themes is a directory bind.
            $sources = array_values(array_filter(
                $bind['sources'],
                static fn (string $path): bool => $fs->fileExists($path) || $fs->directoryExists($path)
            ));
            if ($sources === []) {
                continue;
            }
            $user = $bind['user'] ?? ($bind['image'] === null ? null : $this->imageUser($bind['image']));
            if ($user === null) {
                continue;
            }
            $uid = WritableProjectBinds::uidOf(
                $user,
                ctype_digit(explode(':', $user)[0]) || $bind['image'] === null ? null : $this->imagePasswd($bind['image'])
            );
            if ($uid === null || $uid === $accountUid) {
                continue;
            }
            $projectDir = rtrim($this->project->userAppDirPath(), '/');
            try {
                $handed = $this->project->shell()->execQuiet(
                    ['sh', '-c', self::HAND_OVER, 'sh', $projectDir, "{$uid}:{$accountGid}", ...$sources],
                    [],
                    self::TIMEOUT_SECONDS
                );
            } catch (\Throwable $e) {
                $logger?->warn("Could not hand the paths service {$service} writes to uid {$uid}: " . trim($e->getMessage()));
                continue;
            }
            $relative = array_map(
                static fn (string $path): string => ltrim(substr($path, strlen($projectDir)), '/'),
                array_values(array_filter(array_map('trim', explode("\n", $handed))))
            );
            if ($relative === []) {
                continue;
            }
            $logger?->info(
                "Service {$service} runs as uid {$uid} and writes to " . implode(', ', $relative)
                . ' through a bind mount; handed them to that uid (the account keeps group write)'
            );
        }
    }

    private function imageUser(string $image): ?string
    {
        try {
            $this->project->innerDocker()->ensureImage($image);
            $user = trim($this->project->shell()->execQuiet(
                ['docker', 'image', 'inspect', '--format', '{{.Config.User}}', $image],
                [],
                self::TIMEOUT_SECONDS
            ));
        } catch (\Throwable) {
            return null;
        }

        return $user === '' ? null : $user;
    }

    /** The image's /etc/passwd, read from a created (never started) container. */
    private function imagePasswd(string $image): ?string
    {
        $name = 'pa-passwd-' . bin2hex(random_bytes(4));
        // Created with a dummy entrypoint: create never checks it, and an image without a CMD is refused otherwise.
        $script = 'docker create --name "$1" --entrypoint /pa-none "$2" >/dev/null 2>&1 || exit 0; '
            . 'docker cp "$1:/etc/passwd" - 2>/dev/null | tar -xO 2>/dev/null; '
            . 'docker rm "$1" >/dev/null 2>&1; exit 0';
        try {
            return $this->project->shell()->execQuiet(['sh', '-c', $script, 'sh', $name, $image], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }
    }
}
