<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\EnvFile;
use App\Models\User;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Privileged\AccountFiles;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Dind as DindProject;

/**
 * Puts a manifest's `credentials:` into the account, twice per deploy by {@see PrepareFromSource}: the app
 * config's before its hook, then the chosen manifest's, which is final. Passwords are masked in the log.
 */
class AppCredentialDelivery
{
    /** The env file this deploy last wrote, so the second pass logs only a change. */
    private ?string $written = null;

    public function __construct(private readonly DindProject $dind) {}

    /**
     * @param  bool  $final  false before detection: an absent spec may still come from the manifest detection picks
     */
    public function deliver(?CredentialSpec $spec, bool $final): void
    {
        if ($spec === null && ! $final) {
            return;
        }

        $user = $this->dind->userModel();
        $fs = $this->dind->system()->filesystem();
        $home = rtrim($this->dind->homeDirPath(), '/');
        $path = $home.'/'.AppCredentials::ENV_FILE;
        $logger = $this->dind->shell()->logger();

        $this->keepCredentialsDirPrivate($fs, $path);

        $result = $this->reconcile($spec, $user, $fs, $home);
        $stored = $result['stored'];

        if ($stored === null) {
            $this->forgetCredentials($user, $fs, $path, $logger);

            return;
        }

        $logger?->mask(AppCredentials::secretValues($stored));
        if ($stored !== $user->getAppCredentials()) {
            $user->setAppCredentials($stored);
            $user->save();
        }

        $contents = AppCredentials::envFile($stored);
        if ($contents === $this->written && $fs->fileExists($path)) {
            return;
        }
        $this->writeEnvFile($user, $fs, $path, $contents);
        $this->logDelivered($logger, $stored, $result);
    }

    /**
     * Every deploy, so an account made before ~/.panelalpha was private becomes so; recipes bind only the
     * subdirectories they need, never the whole directory. One the account does not own keeps its mode, with a warning.
     */
    private function keepCredentialsDirPrivate(Filesystem $fs, string $path): void
    {
        if (! $fs->isDir(dirname($path))) {
            return;
        }
        $chmod = $this->dind->system()->runProcess($this->accountFiles()->changeMode('700', dirname($path)));
        if (! $chmod->isSuccessful()) {
            $this->dind->shell()->logger()?->warn('Could not make ~/.panelalpha private: '.trim($chmod->getErrorOutput()));
        }
    }

    /** As the account, so a link planted in ~/.panelalpha reaches nothing else. */
    private function accountFiles(): AccountFiles
    {
        return Privileged::accountFiles(...$this->dind->userModel()->linuxIds());
    }

    /** @return array{stored: ?array<string, mixed>, generated: list<string>, adopted: list<string>, overridden: list<string>} */
    private function reconcile(?CredentialSpec $spec, User $user, Filesystem $fs, string $home): array
    {
        return AppCredentials::reconcile(
            $spec,
            $user->getAppCredentials(),
            $user->getEnvVars(),
            fn (string $relative): ?array => self::readEnvFile($fs, $home, $relative),
            null,
            parse_url((string) $this->dind->publicAppUrl(), PHP_URL_HOST) ?: null,
            is_string($user->email) ? $user->email : null
        );
    }

    /**
     * After the app started ({@see DindDeployMechanics::startApplication()}): the
     * login the app wrote into its `written_by_app` file replaces the stored one, so the API and
     * ~/.panelalpha/app-credentials.env name the login that works. Never fails a deploy: a file not written yet is
     * said in the log, and the stored login stands.
     */
    public function adoptWrittenByApp(): void
    {
        $user = $this->dind->userModel();
        $stored = $user->getAppCredentials();
        $path = is_string($stored['written_by_app'] ?? null) ? $stored['written_by_app'] : null;
        if ($path === null) {
            return;
        }

        $fs = $this->dind->system()->filesystem();
        $home = rtrim($this->dind->homeDirPath(), '/');
        $logger = $this->dind->shell()->logger();
        $result = AppCredentials::adoptWrittenByApp(
            $stored,
            $user->getEnvVars(),
            fn (string $relative): ?array => self::readEnvFile($fs, $home, $relative)
        );
        if ($result['missing']) {
            $logger?->warn("The app has not written ~/{$path}, so the app credentials are still the ones the engine generated");

            return;
        }
        if ($result['adopted'] === [] || $result['stored'] === null) {
            return;
        }

        $logger?->mask(AppCredentials::secretValues($result['stored']));
        $user->setAppCredentials($result['stored']);
        $user->save();
        $this->writeEnvFile($user, $fs, $home.'/'.AppCredentials::ENV_FILE, AppCredentials::envFile($result['stored']));
        $logger?->info("App credentials taken from the login the app wrote in ~/{$path}: ".implode(', ', $result['adopted']));
    }

    /**
     * A dotenv file in the account home as KEY => value; null when it is missing. Reads stay inside the home
     * ({@see Filesystem::fileGetContents()}).
     *
     * @return ?array<string, string>
     */
    private static function readEnvFile(Filesystem $fs, string $home, string $relative): ?array
    {
        $file = $home.'/'.$relative;
        if (! $fs->fileExists($file)) {
            return null;
        }
        $values = [];
        foreach (EnvFile::parse($fs->fileGetContents($file)) as $row) {
            if (($row['type'] ?? null) === 'variable') {
                $values[$row['key']] = $row['value'];
            }
        }

        return $values;
    }

    private function forgetCredentials(User $user, Filesystem $fs, string $path, ?DeployLogger $logger): void
    {
        if ($user->getAppCredentials() !== null) {
            $user->setAppCredentials(null);
            $user->save();
            $logger?->info('App credentials are no longer declared; forgot the stored ones');
        }
        if ($fs->fileExists($path)) {
            $this->dind->system()->exec($this->accountFiles()->removeIfExists($path));
        }
    }

    private function writeEnvFile(User $user, Filesystem $fs, string $path, string $contents): void
    {
        $chown = $user->getChownString();
        $dir = dirname($path);
        if (! $fs->isDir($dir)) {
            $fs->makeDirWithParents($dir, $chown);
            $this->dind->system()->exec($this->accountFiles()->changeMode('700', $dir));
        }
        $fs->writeFileReplacingPath($path, $contents, $chown, '600');
        $this->written = $contents;
    }

    /**
     * Names only: the values are in the file and behind the API, never in a log.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $result
     */
    private function logDelivered(?DeployLogger $logger, array $stored, array $result): void
    {
        $logger?->info('App credentials delivered: '.implode(', ', array_keys($stored['fields'])));
        foreach (['generated' => 'generated', 'adopted' => 'taken from a file already in the account', 'overridden' => 'taken from the project\'s env_vars'] as $key => $how) {
            if ($result[$key] !== []) {
                $logger?->info('App credentials '.$how.': '.implode(', ', $result[$key]));
            }
        }
    }
}
