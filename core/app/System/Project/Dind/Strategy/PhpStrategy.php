<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\FrameworkService;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Php\AccountUserFiles;
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
use App\System\Privileged;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\DeployStrategy;
use Illuminate\Support\Facades\Cache;

/**
 * Laravel and plain PHP applications: a shipped MySQL service is kept, a .env asking for MySQL gets the sidecar
 * and its wait stages, and a platform declaring `database: mysql` gets the account's own ({@see AppDatabase}).
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
     * No Dockerfile: the shared base image is the runtime, and the bind mount the compose file declares
     * ({@see FrameworkService}) is what makes it this project.
     *
     * @param  array<string, mixed>  $decision
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
        $sidecars = $this->runtimeSidecars($strategy, $projectDir, $decision);
        $hasMysql = SidecarEngine::servicesProvide($sidecars['services'], 'mysql');
        $accountDb = ! $hasMysql && ($decision['database'] ?? null) === self::DATABASE_MYSQL;
        $db = $this->databaseSettings($strategy, $projectDir, $artisan, $hasMysql, $accountDb);
        $needsMysql = $hasMysql || MysqlSidecar::isNeeded($db);
        $build = $this->build(
            $projectDir,
            $artisan,
            $needsMysql,
            is_string($decision['app_root'] ?? null) ? trim($decision['app_root'], '/') : '',
            is_array($decision['system_packages'] ?? null) ? array_values($decision['system_packages']) : []
        );

        $this->writeEntrypoint($strategy, $decision, $projectDir, $chown, $artisan, $needsMysql, $appConfig);
        $this->runHostBuild($projectDir, $files, $decision, $build);
        $this->writeCompose(
            $projectDir,
            $chown,
            $db,
            $hasMysql,
            $sidecars,
            $artisan,
            $accountDb,
            $build,
            $decision,
            $this->writeAccountUserFiles($projectDir, $chown, $build)
        );
    }

    /**
     * None for a manifest declaring `database:`: harvested from a workstation compose, they carry its choices,
     * such as OpenCart's `mysql:5.7`, which segfaults on a current kernel.
     *
     * @param  array<string, mixed>  $decision
     * @return array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}
     */
    private function runtimeSidecars(DeployStrategy $strategy, string $projectDir, array $decision): array
    {
        return ($decision['database'] ?? null) === self::DATABASE_MYSQL
            ? ['services' => [], 'volumes' => [], 'env' => []]
            : $strategy->sidecars()->runtimeSidecarsFromProject($projectDir);
    }

    /**
     * The sidecar password goes in before anything reads DB_PASSWORD, so the sidecar and the app share it.
     *
     * @return array<string, mixed>
     */
    private function databaseSettings(DeployStrategy $strategy, string $projectDir, bool $artisan, bool $hasMysql, bool $accountDb): array
    {
        $db = $accountDb
            ? AppDatabase::provision($this->dind->userModel())
            : ($artisan ? EnvFile::databaseSettings($projectDir, $this->dind->projectTree()->read(...)) : []);
        if (! $accountDb && ! $hasMysql && MysqlSidecar::isNeeded($db)) {
            $db = MysqlSidecar::withPassword($db, $strategy->sidecars()->passwords());
        }

        return $db;
    }

    /**
     * Named by the matched manifest, not the strategy (Matomo has its own), with app_root carried
     * so the script lands in the subtree the compose file mounts at /app.
     *
     * @param  array<string, mixed>  $decision
     */
    private function writeEntrypoint(
        DeployStrategy $strategy,
        array $decision,
        string $projectDir,
        ?string $chown,
        bool $artisan,
        bool $needsMysql,
        ?AppConfig $appConfig
    ): void {
        $strategy->entrypoint()->write(
            [
                'platform' => $this->platformId($decision, $artisan),
                'start_command' => '',
                'app_root' => $decision['app_root'] ?? null,
            ],
            $projectDir,
            $chown,
            $this->mysqlWaitStages($needsMysql),
            $appConfig
        );
    }

    /**
     * Composer first, then the frontend, on the host: a PHP project's asset build imports CSS and JS out of vendor/.
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $decision
     */
    private function runHostBuild(string $projectDir, array $files, array $decision, PhpBuild $build): void
    {
        $this->dind->hostCompile()->runPhpBuild(
            $this->runtimeImage($build),
            $decision,
            $build->appRoot,
            $build->composerJson !== null,
            $build->baseImageExtras,
            $build->baseImagePackages
        );
        $frontendBuild = $decision['frontend_build'] ?? null;
        $this->dind->hostCompile()->runForPhp(
            $projectDir,
            $files,
            $build->appRoot,
            $frontendBuild === false || is_string($frontendBuild) ? $frontendBuild : null,
            $this->runtimeImage($build)
        );
    }

    /**
     * Whether passwd/group naming the account uid were written next to the run
     * file. Only on our own base image: the files are read from it on the host.
     */
    private function writeAccountUserFiles(string $projectDir, ?string $chown, PhpBuild $build): bool
    {
        $user = $this->dind->userModel();
        $uid = $user->getUid();
        $gid = $user->getGid();
        if ($build->baseImage === null || $build->baseImage === '' || $uid === null || $gid === null) {
            return false;
        }
        $system = $this->dind->system();
        $files = (new AccountUserFiles(
            fn (array $argv): string => $system->exec($argv, [], 120),
            Cache::store()
        ))->for($build->baseImage, $uid, $gid);
        if ($files === null) {
            return false;
        }

        try {
            $dir = rtrim($projectDir, '/').'/';
            $system->filesystem()->writeFileReplacingPath($dir.EngineArtifacts::RUN_PASSWD, $files['passwd'], $chown, '644');
            $system->filesystem()->writeFileReplacingPath($dir.EngineArtifacts::RUN_GROUP, $files['group'], $chown, '644');
        } catch (\Exception $e) {
            $this->dind->shell()->logger()?->info('Could not write the account user files: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Whether composer.json requires either Symfony package at the root, or, except for Laravel, a thin skeleton
     * (Thelia, Shopware) resolves one through its lock or installed tree; missed, Symfony boots `production` as debug.
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
        $prefix = $build->appRoot === '' ? '' : $build->appRoot.'/';

        return $this->dind->projectTree()->readIn(
            $projectDir,
            $prefix.$build->composer()->vendorDir().'/composer/installed.json'
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function platformId(array $decision, bool $artisan): string
    {
        $platform = $decision['platform'] ?? null;

        return is_string($platform) && $platform !== '' ? $platform : ($artisan ? 'laravel' : 'php');
    }

    /**
     * Reads the application's composer files under its app_root; the repository root's resolve no PHP version.
     *
     * @param  list<string>  $systemPackages  the manifest's `system_packages:`
     */
    private function build(
        string $projectDir,
        bool $artisan,
        bool $needsMysql,
        string $appRoot = '',
        array $systemPackages = []
    ): PhpBuild {
        $prefix = $appRoot === '' ? '' : $appRoot.'/';
        $composerJson = $this->dind->projectTree()->readIn($projectDir, $prefix.'composer.json');
        $composerLock = $this->dind->projectTree()->readIn($projectDir, $prefix.'composer.lock');
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
            appRoot: $appRoot,
            baseImageExtras: $base['baked'],
            baseImagePackages: $base['packages']
        );
    }

    /**
     * Extensions outside the standard set are baked into a host-built variant instead of compiling in every account;
     * `baked` is what the tag carries, as a deferred or failed variant leaves the plain base.
     *
     * @param  list<string>  $extensions
     * @param  list<string>  $systemPackages
     * @return array{tag: ?string, baked: list<string>, packages: list<string>}
     */
    private function baseImage(
        ?string $composerJson,
        ?string $composerLock,
        array $extensions,
        array $systemPackages = []
    ): array {
        $extras = PhpBaseImage::bakeableExtras(PhpBaseImage::missingExtensions($extensions));
        $packages = PhpBaseImage::normalizeSystemPackages($systemPackages);
        $base = $this->dind->innerDocker()->ensurePhpBaseImage(
            PhpRuntime::imageFor($composerJson, $composerLock),
            $extras,
            $packages,
            PhpExtensions::requiredFor(new ComposerManifest($composerJson, $composerLock))
        );

        return [...$base, 'packages' => $packages];
    }

    /**
     * The one image this account builds in and runs on, so composer resolves against the PHP that serves the result.
     */
    private function runtimeImage(PhpBuild $build): string
    {
        return $build->baseImage ?: PhpRuntime::imageFor($build->composerJson, $build->composerLock);
    }

    /**
     * The document root as an environment variable, since the image is shared; an undeclared one is looked for
     * where the index files are, not where a directory exists (Dotclear's empty public/).
     *
     * @param  array<string, mixed>  $decision  the matched manifest's
     * @return array<string, string>
     */
    private function documentRoot(array $decision, PhpBuild $build): array
    {
        $root = $this->dind->userAppDirPath()
            .($build->appRoot === '' ? '' : '/'.trim($build->appRoot, '/'));

        return PhpDocroot::environment(
            $decision['docroot'] ?? null,
            fn (string $relative): bool => $this->dind->system()->filesystem()->fileExists($root.'/'.$relative)
        );
    }

    /**
     * The account the container runs as, set in compose so files in the bind mount stay the customer's
     * and Docker hands the container stdio Apache can open as that uid.
     */
    private function accountIdentity(): string
    {
        $user = $this->dind->userModel();
        $uid = $user->getUid();
        $gid = $user->getGid();

        return $uid === null || $gid === null ? '' : $uid.':'.$gid;
    }

    /**
     * The Composer cache the container mounts, created for the account first or the daemon makes it as root
     * ({@see DindHostBuilder::prepareCacheArgv()}). Best effort, null drops the mount. Root makes only `~/.cache`.
     */
    private function accountComposerCacheDir(): ?string
    {
        $model = $this->dind->userModel();
        if ($model->getUid() === null || $model->getGid() === null) {
            return null;
        }

        try {
            [$uid, $gid] = $model->linuxIds();
            $dir = PhpHostBuild::accountCacheDirFor($model->getHomeDir());
            $system = $this->dind->system();
            $system->exec(Privileged::scripts()->makeHomeEntry(dirname($dir), '0755', $uid.':'.$gid), [], 30);
            $system->exec(Privileged::accountFiles($uid, $gid)->makeDirectories($dir), [], 30);

            return $dir;
        } catch (\Exception $e) {
            $this->dind->shell()->logger()?->info(
                'Container Composer cache unavailable, serving without it: '.$e->getMessage()
            );

            return null;
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function mysqlWaitStages(bool $needsMysql): array
    {
        if (! $needsMysql) {
            return [];
        }
        $wait = ['wait-for-mysql' => MysqlWait::command()];

        return [PlatformStage::INSTALL => $wait, PlatformStage::UPGRADE => $wait];
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  array{services: array<string, mixed>, ...}  $sidecars
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
        array $manifestDecision,
        bool $accountUserFiles = false
    ): void {
        $strategy = $this->dind->strategy();
        $publicUrl = $this->dind->publicAppUrl();
        $databaseConfig = $this->databaseConfig($projectDir, $artisan, $build);
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
        ) + ($accountUserFiles ? ['account_user_files' => true] : []);
        $this->writeFrameworkCompose($strategy, $projectDir, $chown, $decision, $publicUrl);
    }

    /** Read, not run: it decides whether DB_DATABASE for SQLite is a path or a filename. */
    private function databaseConfig(string $projectDir, bool $artisan, PhpBuild $build): ?string
    {
        return $artisan
            ? $this->dind->projectTree()->readIn($projectDir, ($build->appRoot === '' ? '' : $build->appRoot.'/').'config/database.php')
            : null;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function writeFrameworkCompose(DeployStrategy $strategy, string $projectDir, ?string $chown, array $decision, ?string $publicUrl): void
    {
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::framework(
                $strategy->composeDecision($strategy->withPathPrefixKeys($decision, $projectDir)),
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
     * @param  array<string, mixed>  $db
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
            'app_root' => $build->appRoot,
            'user' => $this->accountIdentity(),
            'composer_cache_dir' => $this->accountComposerCacheDir(),
            'env' => $this->environment($db, $hasMysql, $publicUrl, $artisan, $symfony, $databaseConfig, $build, $decision),
        ];
        if ($accountDb) {
            return $this->withAccountDatabaseHost($decision);
        }
        if ($hasMysql || ! MysqlSidecar::isNeeded($db)) {
            return $decision;
        }

        return MysqlSidecar::withSidecar($decision, $db, $this->dind->strategy()->sidecars()->passwords());
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  array<string, mixed>  $decision  the matched manifest's
     * @return array<string, string>
     */
    private function environment(
        array $db,
        bool $hasMysql,
        ?string $publicUrl,
        bool $artisan,
        bool $symfony,
        ?string $databaseConfig,
        PhpBuild $build,
        array $decision
    ): array {
        return PhpEnvironment::withManifest(
            array_merge(
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
            $decision['env'] ?? null
        );
    }

    /**
     * The account's own server is pinned by name, as the nested Docker resolves none of the engine's;
     * no sidecar starts beside it, which would leave the app two databases.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function withAccountDatabaseHost(array $decision): array
    {
        $extraHosts = AppDatabase::extraHosts($this->dind);

        return $extraHosts === [] ? $decision : $decision + ['extra_hosts' => $extraHosts];
    }
}
