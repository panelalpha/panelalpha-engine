<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\EnvFile;
use App\System\Project\Dind as DindProject;

/**
 * Puts a manifest's `credentials:` into the account: reconciles the stored
 * values on the project, writes ~/.panelalpha/app-credentials.env before the
 * recipe's prepare hook, and masks the passwords in this deploy's log.
 *
 * Called twice per deploy by {@see PrepareFromSource}: before the app config's
 * hook with what the app config declares, and after detection with what the
 * chosen manifest declares, which is the final word.
 */
class AppCredentialDelivery
{
    /** The env file this deploy last wrote, so the second pass logs only a change. */
    private ?string $written = null;

    public function __construct(private readonly DindProject $dind)
    {
    }

    /**
     * @param bool $final false before detection: a spec that is absent may
     *        still come from the manifest detection picks, so nothing is dropped yet
     */
    public function deliver(?CredentialSpec $spec, bool $final): void
    {
        if ($spec === null && !$final) {
            return;
        }

        $user = $this->dind->userModel();
        $fs = $this->dind->system()->filesystem();
        $home = rtrim($this->dind->homeDirPath(), '/');
        $path = $home . '/' . AppCredentials::ENV_FILE;
        $logger = $this->dind->shell()->logger();

        // Every deploy, so an account made before ~/.panelalpha was private becomes so.
        // Recipes bind only the subdirectories they need, never the whole directory.
        if ($fs->isDir(dirname($path))) {
            $this->dind->system()->exec(['sudo', 'chmod', '700', dirname($path)]);
        }

        $result = AppCredentials::reconcile(
            $spec,
            $user->getAppCredentials(),
            $user->getEnvVars(),
            function (string $relative) use ($fs, $home): ?array {
                $file = $home . '/' . $relative;
                if (!$fs->fileExists($file)) {
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
        $stored = $result['stored'];

        if ($stored === null) {
            if ($user->getAppCredentials() !== null) {
                $user->setAppCredentials(null);
                $user->save();
                $logger?->info('App credentials are no longer declared; forgot the stored ones');
            }
            if ($fs->fileExists($path)) {
                $this->dind->system()->exec(['sudo', 'rm', '-f', $path]);
            }

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
        $chown = $user->getChownString();
        $dir = dirname($path);
        if (!$fs->isDir($dir)) {
            $fs->makeDirWithParents($dir, $chown);
            $this->dind->system()->exec(['sudo', 'chmod', '700', $dir]);
        }
        $fs->filePutContents($path, $contents, $chown, '600');
        $this->written = $contents;

        // Names only: the values are in the file and behind the API, never in a log.
        $logger?->info('App credentials delivered: ' . implode(', ', array_keys($stored['fields'])));
        foreach (['generated' => 'generated', 'adopted' => 'taken from a file already in the account', 'overridden' => 'taken from the project\'s env_vars'] as $key => $how) {
            if ($result[$key] !== []) {
                $logger?->info('App credentials ' . $how . ': ' . implode(', ', $result[$key]));
            }
        }
    }
}
