<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\WritableProjectBinds;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Support\JobBudget;
use App\System\Filesystem;
use App\System\Project\Dind as DindProject;

/**
 * Hands checkout paths a non-root service writes through a bind mount to its uid before `compose up`
 * ({@see WritableProjectBinds}), creating a missing one first; the group stays the account's with group
 * write, nothing wider. Every strategy: a Dockerfile recipe keeps the repository's MinIO too.
 */
final class BindSourceOwners
{
    private const TIMEOUT_SECONDS = 60;

    /** An inner command's own budget: a hand-over chowns whatever a bind mount holds. */
    private const HAND_OVER_SECONDS = JobBudget::COMMAND_SECONDS;

    /**
     * Run as root in the account: $1 the checkout, $2 uid:gid, then the paths. One resolving outside the
     * checkout (a committed symlink to /usr) is skipped, and nothing below it is followed (-h, -P).
     */
    private const HAND_OVER = 'root=$1; owner=$2; shift 2; for p; do '
        .'[ -L "$p" ] && continue; r=$(realpath -e -- "$p" 2>/dev/null) || continue; '
        .'case "$r" in "$root"/*) ;; *) continue ;; esac; '
        .'chown -R -h -P "$owner" -- "$r" && chmod -R g+w -- "$r" && printf "%s\n" "$r"; done';

    public function __construct(private DindProject $project) {}

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
        if (! is_array($compose)) {
            return;
        }

        $fs = $this->project->system()->filesystem();
        $env = $this->interpolationEnv();
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $builtHere = DeployCompose::imagesBuiltHere(array_values($services), $env);
        foreach (WritableProjectBinds::of($compose, $this->project->userAppDirPath()) as $service => $bind) {
            $this->handOverBind($service, $bind, $fs, $services, $env, $builtHere, $accountUid, $accountGid, $logger);
        }
    }

    /** @return array<string, list<?string>|string> */
    private function interpolationEnv(): array
    {
        try {
            $env = $this->project->environment()->forInterpolation();
        } catch (\Throwable) {
            $env = [];
        }

        return $env;
    }

    /**
     * One service's bound paths that exist, handed to the uid it runs as; its image is interpolated first,
     * since `wordpress:${WORDPRESS_VERSION:-latest}` is an image only then.
     *
     * @param  array{sources: list<string>, image: ?string, user: ?string}  $bind
     * @param  array<string, mixed>  $services
     * @param  array<string, list<?string>|string>  $env
     * @param  list<string>  $builtHere
     */
    private function handOverBind(
        int|string $service,
        array $bind,
        Filesystem $fs,
        array $services,
        array $env,
        array $builtHere,
        int $accountUid,
        int $accountGid,
        ?DeployLogger $logger
    ): void {
        $image = $bind['image'] === null ? null : DeployCompose::resolvedImageRef($bind['image'], $env);
        $build = $this->buildOf($services[$service] ?? null, $image, $services, $env, $builtHere);
        [$user, $passwdImage] = $this->runsAs($bind, $image, $build);
        if ($user === null) {
            return;
        }
        $uid = WritableProjectBinds::uidOf(
            $user,
            ctype_digit(explode(':', $user)[0]) || $passwdImage === null ? null : $this->imagePasswd($passwdImage)
        );
        if ($uid === null || $uid === $accountUid) {
            return;
        }
        $this->createMissingDirectories($service, $bind['sources'], $fs, $logger);
        $sources = self::existingSources($bind['sources'], $fs);
        if ($sources !== []) {
            $this->handOverTo($service, $uid, $accountGid, $sources, $logger);
        }
    }

    /**
     * A source not in the checkout yet is created as the account, as the directory Docker would otherwise
     * create as root: MinIO's `./data/media:/data` under `user: 1000`.
     *
     * @param  list<string>  $sources
     */
    private function createMissingDirectories(int|string $service, array $sources, Filesystem $fs, ?DeployLogger $logger): void
    {
        $missing = array_values(array_diff($sources, self::existingSources($sources, $fs)));
        $created = (new BindSourceDirectories($this->project))->createMissing($missing);
        if ($created !== []) {
            $logger?->info('Created '.implode(', ', $created).", which service {$service} binds and does not run as root");
        }
    }

    /**
     * Directories too: keycloak's ./keycloak/themes is a directory bind.
     *
     * @param  list<string>  $sources
     * @return list<string>
     */
    private static function existingSources(array $sources, Filesystem $fs): array
    {
        return array_values(array_filter(
            $sources,
            static fn (string $path): bool => $fs->fileExists($path) || $fs->directoryExists($path)
        ));
    }

    /**
     * The user the service runs as, and the image to read its passwd from; never an image `docker create`
     * would ask a registry for because it is not built yet.
     *
     * @param  array{sources: list<string>, image: ?string, user: ?string}  $bind
     * @param  array<string, mixed>|string|null  $build
     * @return array{0: ?string, 1: ?string}
     */
    private function runsAs(array $bind, ?string $image, array|string|null $build): array
    {
        if ($bind['user'] !== null) {
            $user = $bind['user'];
            $passwdImage = $build !== null && ($image === null || ! $this->hasLocalImage($image)) ? null : $image;
        } elseif ($build !== null) {
            [$user, $passwdImage] = $this->builtImageUser($image, $build);
        } else {
            $user = $image === null ? null : $this->imageUser($image);
            $passwdImage = $image;
        }

        return [$user, $passwdImage];
    }

    /** @param  list<string>  $sources */
    private function handOverTo(int|string $service, int $uid, int $accountGid, array $sources, ?DeployLogger $logger): void
    {
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        try {
            $handed = $this->project->shell()->execQuiet(
                ['sh', '-c', self::HAND_OVER, 'sh', $projectDir, "{$uid}:{$accountGid}", ...$sources],
                [],
                self::HAND_OVER_SECONDS
            );
        } catch (\Throwable $e) {
            $logger?->warn("Could not hand the paths service {$service} writes to uid {$uid}: ".trim($e->getMessage()));

            return;
        }
        $relative = array_map(
            static fn (string $path): string => ltrim(substr($path, strlen($projectDir)), '/'),
            array_values(array_filter(array_map('trim', explode("\n", $handed))))
        );
        if ($relative === []) {
            return;
        }
        $logger?->info(
            "Service {$service} runs as uid {$uid} and writes to ".implode(', ', $relative)
            .' through a bind mount; handed them to that uid (the account keeps group write)'
        );
    }

    /**
     * The `build:` that produces this service's image: its own, or that of the service building the tag it
     * runs (kassambara's `image: wpcli`); null for an image from a registry.
     *
     * @param  array<string, mixed>  $services
     * @param  array<string, list<?string>|string>  $env
     * @param  list<string>  $builtHere
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
        if (! in_array(strtolower($image), $builtHere, true)) {
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
     * Who an image this file builds runs as, never asking a registry for the tag: the Dockerfile's `USER`,
     * else the image a previous deploy built, else the base it starts from.
     *
     * @param  array<string, mixed>|string  $build
     * @return array{0: ?string, 1: ?string} the user, and the image to read its passwd from
     */
    private function builtImageUser(?string $image, array|string $build): array
    {
        $projectDir = rtrim($this->project->userAppDirPath(), '/');
        $path = ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build);
        if ($path !== null && ! str_starts_with($path, '/')) {
            $path = $projectDir.'/'.$path;
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
            return $this->project->accountDaemon()->image($image, self::TIMEOUT_SECONDS) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function inspectUser(string $image): ?string
    {
        try {
            $held = $this->project->accountDaemon()->image($image, self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }
        $user = trim((string) ($held['Config']['User'] ?? ''));

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

    /** The image's /etc/passwd; null when the daemon cannot read it. */
    private function imagePasswd(string $image): ?string
    {
        try {
            return $this->project->accountDaemon()->readImageFile($image, '/etc/passwd', self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }
    }
}
