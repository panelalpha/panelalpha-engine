<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\WritableProjectBinds;
use App\System\Project\Dind as DindProject;

/**
 * Hands checkout paths a non-root service writes through a bind mount to the
 * uid it runs as, before `compose up` ({@see WritableProjectBinds}).
 *
 * Owner becomes that uid, the group stays the account's with group write, so
 * the account keeps writing them too; nothing is widened to other uids.
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
        try {
            $env = $this->project->environment()->forInterpolation();
        } catch (\Throwable) {
            $env = [];
        }
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $builtHere = DeployCompose::imagesBuiltHere(array_values($services), $env);
        foreach (WritableProjectBinds::of($compose, $this->project->userAppDirPath()) as $service => $bind) {
            // Directories too: keycloak's ./keycloak/themes is a directory bind.
            $sources = array_values(array_filter(
                $bind['sources'],
                static fn (string $path): bool => $fs->fileExists($path) || $fs->directoryExists($path)
            ));
            if ($sources === []) {
                continue;
            }
            // `wordpress:${WORDPRESS_VERSION:-latest}` is an image only once interpolated.
            $image = $bind['image'] === null ? null : DeployCompose::resolvedImageRef($bind['image'], $env);
            $build = $this->buildOf($services[$service] ?? null, $image, $services, $env, $builtHere);
            if ($bind['user'] !== null) {
                $user = $bind['user'];
                // `docker create` would ask a registry for a tag not built yet.
                $passwdImage = $build !== null && ($image === null || !$this->hasLocalImage($image)) ? null : $image;
            } elseif ($build !== null) {
                [$user, $passwdImage] = $this->builtImageUser($image, $build);
            } else {
                $user = $image === null ? null : $this->imageUser($image);
                $passwdImage = $image;
            }
            if ($user === null) {
                continue;
            }
            $uid = WritableProjectBinds::uidOf(
                $user,
                ctype_digit(explode(':', $user)[0]) || $passwdImage === null ? null : $this->imagePasswd($passwdImage)
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

    /**
     * The `build:` that produces this service's image: its own, or the one of
     * the service building the tag it runs (kassambara's `image: wpcli`).
     * Null for an image from a registry.
     *
     * @param array<string, mixed> $services
     * @param array<string, list<?string>|string> $env
     * @param list<string> $builtHere
     * @return array<string, mixed>|string|null
     */
    private function buildOf(mixed $service, ?string $image, array $services, array $env, array $builtHere): array|string|null
    {
        if ($image === null) {
            return null;
        }
        if (is_array($service) && (is_array($service['build'] ?? null) || is_string($service['build'] ?? null))) {
            return $service['build'];
        }
        if (!in_array(strtolower($image), $builtHere, true)) {
            return null;
        }
        foreach ($services as $other) {
            if (is_array($other) && (is_array($other['build'] ?? null) || is_string($other['build'] ?? null))
                && DeployCompose::resolvedImageRef($other['image'] ?? null, $env) === $image
            ) {
                return $other['build'];
            }
        }

        return null;
    }

    /**
     * Who an image this file builds runs as, without asking a registry for a
     * tag that only exists once the build runs: the Dockerfile's own `USER`,
     * else the image a previous deploy built, else the base it starts from.
     *
     * @param array<string, mixed>|string $build
     * @return array{0: ?string, 1: ?string} the user, and the image to read its passwd from
     */
    private function builtImageUser(?string $image, array|string $build): array
    {
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        $path = ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build);
        if ($path !== null && !str_starts_with($path, '/')) {
            $path = $projectDir . '/' . $path;
        }
        $fs = $this->project->system()->filesystem();
        $from = ['user' => null, 'base' => null];
        try {
            if ($path !== null && $fs->fileExists($path)) {
                $from = WritableProjectBinds::dockerfileUser((string) $fs->fileGetContents($path));
            }
        } catch (\Throwable) {
        }
        $present = $image !== null && $this->hasLocalImage($image);
        if ($from['user'] !== null) {
            return [$from['user'], $present ? $image : null];
        }
        if ($present) {
            return [$this->inspectUser($image), $image];
        }
        $base = $from['base'] === null ? null : DeployCompose::resolvedImageRef($from['base']);
        if ($base === null || str_starts_with($base, 'scratch:')) {
            return [null, null];
        }

        return [$this->imageUser($base), $base];
    }

    private function hasLocalImage(string $image): bool
    {
        try {
            $this->project->shell()->execQuiet(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function inspectUser(string $image): ?string
    {
        try {
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

    private function imageUser(string $image): ?string
    {
        try {
            $this->project->innerDocker()->ensureImage($image);
        } catch (\Throwable) {
            return null;
        }

        return $this->inspectUser($image);
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
