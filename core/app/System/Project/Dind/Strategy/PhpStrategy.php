<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\AppDatabase;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Php\ComposerManifest;
use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use App\Lib\Deploy\Platform\Runtime\Php\MysqlWait;
use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\Lib\Deploy\Platform\Runtime\Php\PhpDocroot;
use App\Lib\Deploy\Platform\Runtime\Php\PhpEnvironment;
use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * Laravel and plain PHP applications.
 *
 * The database question is what shapes this one, and there are three answers.
 * A project that ships its own MySQL service is already pointed at one and
 * must not get a second. A Laravel project whose .env asks for MySQL and
 * ships nothing gets the engine's sidecar, plus the wait stages that stop
 * migrations racing a database that is still starting. And a platform that
 * declares `database: mysql` -- Matomo, and the PHP applications shaped like
 * it, which have no .env to read and no compose file to ship -- gets one
 * provisioned on the account's own MySQL server. {@see AppDatabase}.
 */
class PhpStrategy
{
    private const DATABASE_MYSQL = 'mysql';

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * @param array<string, mixed> $decision
     */
    public function apply(
        string $projectDir,
        ?string $chown,
        bool $artisan,
        ?AppConfig $appConfig = null,
        array $decision = []
    ): void {
        $strategy = $this->dind->strategy();
        $files = ProjectContext::listRootFiles($projectDir);
        // A manifest that declares `database:` has already answered the
        // question those sidecars exist to answer, and answered it for
        // hosting: the account's own MySQL, which the panel can back up and
        // the customer can open. The sidecars are harvested from a
        // *workstation* compose ({@see RuntimeSidecars}) and carry that file's
        // choices with them -- OpenCart's pins `mysql:5.7`, which is years
        // past end of life and segfaults on boot on a current kernel, taking
        // the application container down with it.
        $sidecars = ($decision['database'] ?? null) === self::DATABASE_MYSQL
            ? ['services' => [], 'volumes' => [], 'env' => []]
            : $strategy->sidecars()->runtimeSidecarsFromProject($projectDir);
        // MariaDB and Percona resolve to the same engine, so one check covers
        // every MySQL-compatible sidecar the project might ship.
        $hasMysql = SidecarEngine::servicesProvide($sidecars['services'], 'mysql');
        $accountDb = !$hasMysql && ($decision['database'] ?? null) === self::DATABASE_MYSQL;
        $db = $accountDb
            ? AppDatabase::provision($this->dind->userModel())
            : ($artisan ? EnvFile::databaseSettings($projectDir, $this->dind->projectTree()->read(...)) : []);
        if (!$accountDb && !$hasMysql && MysqlSidecar::isNeeded($db)) {
            // Before anything reads DB_PASSWORD, so the sidecar and the app
            // get the same one (engine#189).
            $db = MysqlSidecar::withPassword($db, $strategy->sidecars()->passwords());
        }
        $needsMysql = $hasMysql || MysqlSidecar::isNeeded($db);
        $build = $this->build(
            $projectDir,
            $artisan,
            $needsMysql,
            is_string($decision['app_root'] ?? null) ? trim($decision['app_root'], '/') : '',
            is_array($decision['system_packages'] ?? null) ? array_values($decision['system_packages']) : []
        );

        $strategy->entrypoint()->write(
            // The manifest that was actually matched, not the strategy it
            // shares: Matomo runs the PHP strategy but has its own manifest,
            // and taking the strategy's name here would hand it php.yaml's
            // entrypoint and drop every command it declared for itself.
            [
                'platform' => $this->platformId($decision, $artisan),
                'start_command' => '',
                // Carried through so the script is written into the subtree
                // the compose file mounts at /app. Rebuilding this array
                // without it put the entrypoint outside the mount for every
                // manifest with an app_root.
                'app_root' => $decision['app_root'] ?? null,
            ],
            $projectDir,
            $chown,
            $this->mysqlWaitStages($needsMysql),
            $appConfig
        );
        // The build, on the host, before anything is started. There is no
        // per-project image to resolve vendor/ inside any more, and the
        // container is about to serve this directory as-is.
        //
        // Composer first, then the frontend: a PHP project's asset build
        // imports CSS and JS out of vendor/ -- Filament, Symfony and Bagisto
        // all do -- so it needs a resolved tree to read. That used to be the
        // other way round, with the Node pass resolving vendor/ itself out of
        // the composer image before it started.
        $this->dind->hostCompile()->runPhpBuild(
            $this->runtimeImage($build),
            $decision,
            $build->appRoot,
            $build->composerJson !== null
        );
        $frontendBuild = $decision['frontend_build'] ?? null;
        $this->dind->hostCompile()->runForPhp(
            $projectDir,
            $files,
            $build->appRoot,
            $frontendBuild === false || is_string($frontendBuild) ? $frontendBuild : null,
            $this->runtimeImage($build)
        );
        // No Dockerfile. The shared base image is the runtime -- Apache, the
        // extension set, composer and the entrypoint shim are all baked into
        // it -- and what makes it this project is the bind mount the compose
        // file declares. {@see \App\Lib\Deploy\Compose\FrameworkService}.
        $this->writeCompose($projectDir, $chown, $db, $hasMysql, $sidecars, $artisan, $accountDb, $build, $decision);
    }

    /**
     * Whether this is a Symfony application, by its own composer.json.
     *
     * Both spellings, because `symfony/symfony` is what an application on the
     * old monolithic package requires and `symfony/framework-bundle` is what
     * one on the components requires. Neither is something a non-Symfony
     * project pulls in at the root.
     *
     * A product shipped as a thin skeleton (Thelia, Shopware, Pimcore)
     * requires the framework through its own core package, so the lock and
     * the installed tree count too -- except for Laravel, which stays Laravel
     * whatever it pulls in. Missed, the app got `production`, which Symfony
     * boots as a debug environment.
     */
    public static function isSymfony(PhpBuild $build, ?string $installedJson = null): bool
    {
        $composer = $build->composer();
        if ($composer->rootRequires('symfony/framework-bundle') || $composer->rootRequires('symfony/symfony')) {
            return true;
        }
        if ($build->artisan) {
            return false;
        }

        return $composer->resolves('symfony/framework-bundle', $installedJson)
            || $composer->resolves('symfony/symfony', $installedJson);
    }

    /** The installed tree's package list, written by the host build before the compose file. */
    private function installedPackages(string $projectDir, PhpBuild $build): ?string
    {
        $prefix = $build->appRoot === '' ? '' : $build->appRoot . '/';

        return $this->dind->projectTree()->readIn(
            $projectDir,
            $prefix . $build->composer()->vendorDir() . '/composer/installed.json'
        );
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function platformId(array $decision, bool $artisan): string
    {
        $platform = $decision['platform'] ?? null;

        return is_string($platform) && $platform !== '' ? $platform : ($artisan ? 'laravel' : 'php');
    }

    /**
     * @param list<string> $systemPackages the manifest's `system_packages:`
     */
    private function build(
        string $projectDir,
        bool $artisan,
        bool $needsMysql,
        string $appRoot = '',
        array $systemPackages = []
    ): PhpBuild {
        // The application's manifests, not the repository's: for a project
        // whose manifest declares an app_root these are <root>/composer.json
        // and <root>/composer.lock, and reading the repository root instead
        // resolves no PHP version and no extensions.
        $prefix = $appRoot === '' ? '' : $appRoot . '/';
        $composerJson = $this->dind->projectTree()->readIn($projectDir, $prefix . 'composer.json');
        $composerLock = $this->dind->projectTree()->readIn($projectDir, $prefix . 'composer.lock');
        $base = $this->baseImage(
            $composerJson,
            $composerLock,
            PhpExtensions::for(new ComposerManifest($composerJson, $composerLock), $artisan, $needsMysql),
            $systemPackages
        );

        return new PhpBuild(
            composerJson: $composerJson,
            composerLock: $composerLock,
            artisan: $artisan,
            waitForMysql: $needsMysql,
            baseImage: $base['tag'],
            appRoot: $appRoot
        );
    }

    /**
     * Extensions outside the standard baked set (imagick and friends) cost
     * ~36s of compiling inside the account every single time. Bake them into
     * a host-built variant instead, so the second account wanting the same
     * set gets them as a ~2s image load.
     *
     * @param list<string> $extensions
     * @param list<string> $systemPackages
     * @return array{tag: ?string, baked: list<string>}
     */
    private function baseImage(
        ?string $composerJson,
        ?string $composerLock,
        array $extensions,
        array $systemPackages = []
    ): array {
        return $this->dind->innerDocker()->ensurePhpBaseImage(
            PhpRuntime::imageFor($composerJson, $composerLock),
            PhpBaseImage::bakeableExtras(PhpBaseImage::missingExtensions($extensions)),
            $systemPackages,
            PhpExtensions::requiredFor(new ComposerManifest($composerJson, $composerLock))
        );
    }

    /**
     * The image this account both builds in and runs on.
     *
     * One image for both halves on purpose: composer resolving against a
     * different PHP than the one that will serve the result is how a package
     * needing ext-soap gets installed into a runtime that has none.
     */
    private function runtimeImage(PhpBuild $build): string
    {
        return $build->baseImage ?: PhpRuntime::imageFor($build->composerJson, $build->composerLock);
    }

    /**
     * The document root, as the manifest declared it.
     *
     * Passed as an environment variable rather than baked into a serve
     * command, because the image is shared and the answer is per-project.
     * A manifest that declares none says nothing here, and the image's serve
     * script decides by looking for a public/ directory -- which is the right
     * default for a PHP application and the one every recipe was spelling out
     * by hand.
     *
     * @param array<string, mixed> $decision the matched manifest's
     * @return array<string, string>
     */
    private function documentRoot(array $decision, PhpBuild $build): array
    {
        // Undeclared: look where the index files are, not just which directories
        // exist -- the image's `-d /app/public` serves Dotclear's empty public/.
        $root = $this->dind->userAppDirPath()
            . ($build->appRoot === '' ? '' : '/' . trim($build->appRoot, '/'));

        return PhpDocroot::environment(
            $decision['docroot'] ?? null,
            fn (string $relative): bool => $this->dind->system()->filesystem()->fileExists($root . '/' . $relative)
        );
    }

    /**
     * The account the container runs as, as compose's `user:` spells it.
     *
     * The project is a bind mount now, owned by the account's own OS user, so
     * the container has to be that user -- otherwise every file the
     * application writes lands owned by root inside the customer's directory,
     * where their SFTP and the file manager cannot reach it.
     *
     * Said once, in compose, rather than by dropping privileges inside the
     * container: Docker then creates the container's stdio owned by this uid,
     * which is what lets Apache open `ErrorLog /dev/stderr` without ever
     * having been root. A process that starts as root and drops cannot --
     * its stdio still belongs to root, and reopening it fails with
     * `AH00091: could not open error log file /dev/stderr`.
     */
    private function accountIdentity(): string
    {
        $user = $this->dind->userModel();
        $uid = $user->getUid();
        $gid = $user->getGid();

        return $uid === null || $gid === null ? '' : $uid . ':' . $gid;
    }

    /**
     * The Composer cache the application container mounts, created and handed
     * to the account before the compose file names it.
     *
     * Creating it here is the load-bearing part, and it is the same lesson as
     * {@see \App\Lib\Deploy\Dind\DindHostBuilder::prepareCacheArgv()}: a
     * bind-mount source that does not exist is created by the daemon as root,
     * and a container running as the account then cannot write it. The daemon
     * in question is the account's nested one, which resolves the path inside
     * the account container -- so the directory has to be under `/home/<user>`,
     * the one path that is the same on both sides.
     *
     * Best-effort, deliberately. A cache is an optimisation and a host that
     * will not let the engine create one is no reason to refuse the deploy;
     * returning null drops the mount, which is strictly better than mounting a
     * directory the account cannot write.
     */
    private function accountComposerCacheDir(): ?string
    {
        $model = $this->dind->userModel();
        $chown = $model->getChownString();
        if (!is_string($chown) || $chown === '') {
            return null;
        }

        try {
            $dir = PhpHostBuild::accountCacheDirFor($model->getHomeDir());
            $system = $this->dind->system();
            $system->exec(['sudo', 'mkdir', '-p', $dir], [], 30);
            $system->exec(['sudo', 'chown', $chown, $dir], [], 30);

            return $dir;
        } catch (\Exception $e) {
            $this->dind->shell()->logger()?->info(
                'Container Composer cache unavailable, serving without it: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function mysqlWaitStages(bool $needsMysql): array
    {
        if (!$needsMysql) {
            return [];
        }
        $wait = ['wait-for-mysql' => MysqlWait::command()];

        return [PlatformStage::INSTALL => $wait, PlatformStage::UPGRADE => $wait];
    }

    /**
     * @param array<string, mixed> $db
     * @param array{services: array<string, mixed>, ...} $sidecars
     */
    private function writeCompose(
        string $projectDir,
        ?string $chown,
        array $db,
        bool $hasMysql,
        array $sidecars,
        bool $artisan,
        bool $accountDb,
        PhpBuild $build,
        array $manifestDecision
    ): void {
        $strategy = $this->dind->strategy();
        $publicUrl = $this->dind->publicAppUrl();
        // Read, not run: it decides whether DB_DATABASE for SQLite is a path or a filename.
        $databaseConfig = $artisan
            ? $this->dind->projectTree()->readIn($projectDir, ($build->appRoot === '' ? '' : $build->appRoot . '/') . 'config/database.php')
            : null;
        $decision = $strategy->sidecars()->mergeRuntimeSidecars(
            $this->decision(
                $db,
                $hasMysql,
                $publicUrl,
                $artisan,
                $accountDb,
                $build,
                $manifestDecision,
                $databaseConfig,
                self::isSymfony($build, $this->installedPackages($projectDir, $build))
            ),
            $sidecars
        );
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::framework(
                $strategy->composeDecision($decision),
                PhpBaseImage::PORT,
                $publicUrl
            ),
            $chown
        );
    }

    /**
     * A project that ships its own MySQL service is already pointed at one;
     * only an app with none gets the engine's sidecar.
     *
     * @param array<string, mixed> $db
     * @return array<string, mixed>
     */
    private function decision(
        array $db,
        bool $hasMysql,
        ?string $publicUrl,
        bool $artisan,
        bool $accountDb,
        PhpBuild $build,
        array $decision,
        ?string $databaseConfig = null,
        bool $symfony = false
    ): array {
        $decision = [
            'runtime' => 'php',
            'image' => $this->runtimeImage($build),
            // Passed through from the manifest, not worked out here: a
            // repository whose application is not at its root declares
            // `app_root`, and the compose file mounts that subtree so
            // everything downstream still works on /app.
            'app_root' => $build->appRoot,
            'user' => $this->accountIdentity(),
            // The container only reaches for this when the entrypoint has to
            // restore a vendor/ that went missing after the deploy -- rare,
            // but that is exactly when resolving from packagist instead is
            // slowest and least welcome. Null when the directory could not be
            // prepared, which drops the mount rather than handing the account
            // one it cannot write. {@see accountComposerCacheDir()}.
            'composer_cache_dir' => $this->accountComposerCacheDir(),
            'env' => array_merge(
                PhpEnvironment::for(
                    $hasMysql ? array_merge($db, ['connection' => 'mysql']) : $db,
                    $publicUrl,
                    $artisan,
                    $symfony,
                    $databaseConfig
                ),
                $this->documentRoot($decision, $build),
                $this->dind->strategy()->entrypoint()->deployPhaseEnvironment()
            ),
        ];
        if ($accountDb) {
            // A sidecar shares the app's network namespace and answers on
            // 127.0.0.1; the account's own server does not, and the app's
            // nested Docker resolves none of the engine's names. Pin it — and
            // do not also start a sidecar, which would leave the app with two
            // databases and its data in whichever one it reached first.
            $extraHosts = AppDatabase::extraHosts($this->dind);

            return $extraHosts === [] ? $decision : $decision + ['extra_hosts' => $extraHosts];
        }
        if ($hasMysql || !MysqlSidecar::isNeeded($db)) {
            return $decision;
        }

        return MysqlSidecar::withSidecar($decision, $db, $this->dind->strategy()->sidecars()->passwords());
    }

}
