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
            function (string $relative) use ($fs, $home): ?array {
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
            },
            null,
            parse_url((string) $this->dind->publicAppUrl(), PHP_URL_HOST) ?: null,
            is_string($user->email) ? $user->email : null
        );
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
