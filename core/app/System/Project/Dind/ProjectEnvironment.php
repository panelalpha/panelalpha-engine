<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\GitRepository;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\Strategies;
use Symfony\Component\Yaml\Yaml;

/**
 * The .env files the application is deployed with.
 *
 * Three of them, and the distinction matters on every redeploy:
 * `.env.default` records the base the project itself shipped, `.env` is what
 * the container actually reads, and the account's own env_vars are merged
 * over the base rather than replacing it — an empty field in the panel means
 * "keep what the project shipped", not "set this to nothing".
 *
 * A `.env` the repository tracks is the client's, not the engine's (ADR-0001
 * D3): it is left as committed and the env_vars go to `.env.panelalpha`
 * instead, loaded after `.env` by the services that load `.env`.
 */
class ProjectEnvironment
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * After clone/setup (or before compose up on rebuild): write .env.default
     * from the current base, then optionally merge user-supplied env_vars
     * onto .env.
     */
    public function apply(): void
    {
        $user = $this->dind->userModel();
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $projectDir = $this->dind->userAppDirPath();
        $envPath = "{$projectDir}/.env";
        $examplePath = "{$projectDir}/.env.example";
        $defaultPath = "{$projectDir}/.env.default";
        $chown = $user->getChownString();
        $logger = $this->dind->shell()->logger();

        // Only non-empty values count as overrides (empty field = keep base).
        $overrides = [];
        foreach ($user->getEnvVars() as $key => $value) {
            if ($value !== '') {
                $overrides[$key] = $value;
            }
        }

        $baseContents = null;
        $source = 'none';
        if ($fs->fileExists($envPath)) {
            $baseContents = $fs->fileGetContents($envPath);
            $source = 'after-clone';
        } elseif ($fs->fileExists($examplePath)) {
            $baseContents = self::withGeneratedSecrets((string) $fs->fileGetContents($examplePath));
            [$baseContents, $replaced] = self::withoutPublishedSecrets(
                $baseContents,
                $this->dind->strategy()->secrets()->for('compose-placeholders'),
                $overrides
            );
            foreach ($replaced as $key) {
                $logger?->info("Replaced the published placeholder in {$key} from .env.example with a generated secret");
            }
            $baseContents = $this->withoutComposeDefaultedKeys($baseContents);
            [$baseContents, $blanks] = self::withoutTemplatePlaceholders($baseContents, $overrides);
            if ($blanks !== []) {
                $logger?->info(
                    'Left to the image, which sets them: .env.example had blanks to fill in for '
                    . implode(', ', $blanks)
                );
            }
            $source = '.env.example';
        }

        if ($baseContents !== null) {
            $fs->filePutContents($defaultPath, $baseContents, $chown, '644');
        }

        $envOverridesPath = $projectDir . '/' . EngineArtifacts::ENV_OVERRIDES;
        if ($overrides !== [] && $this->envIsTracked()) {
            $fs->filePutContents($envOverridesPath, EnvFile::merge('', $overrides), $chown, '644');
            $services = $this->syncRunFileEnvOverrides(true);
            $keys = array_keys($overrides);
            $logger?->info(
                'The repository tracks .env, so it is left as committed. Using user-provided environment variables ('
                . count($keys)
                . ' keys) from ' . EngineArtifacts::ENV_OVERRIDES
                . ($services === [] ? '' : ', loaded after .env by: ' . implode(', ', $services))
                . ': ' . implode(', ', $keys)
            );
            $logger?->warn(
                'Code that reads .env from disk will not see these overrides, including build-time readers '
                . 'such as Vite or Next.js. Only the container environment carries them.'
            );
            $this->materializeNestedEnvExamples($projectDir, $chown);
            $user->setDetails(['used_custom_env_vars' => true]);
            $user->save();

            return;
        }

        // No overrides, or a .env the engine owns: a .env.panelalpha from an
        // earlier deploy would otherwise keep reapplying values removed since.
        if ($fs->fileExists($envOverridesPath)) {
            $system->exec(['sudo', 'rm', '-f', $envOverridesPath]);
        }
        $this->syncRunFileEnvOverrides(false);

        if ($overrides !== []) {
            $merged = EnvFile::merge($baseContents ?? '', $overrides);
            $fs->filePutContents($envPath, $merged, $chown, '644');
            $keys = array_keys($overrides);
            $logger?->info(
                'Using user-provided environment variables ('
                . count($keys)
                . ' keys overridden): '
                . implode(', ', $keys)
            );
            $this->materializeNestedEnvExamples($projectDir, $chown);
            $user->setDetails(['used_custom_env_vars' => true]);
            $user->save();

            return;
        }

        if ($baseContents !== null && !$fs->fileExists($envPath)) {
            $fs->filePutContents($envPath, $baseContents, $chown, '644');
        }
        $this->materializeNestedEnvExamples($projectDir, $chown);
        $logger?->info("Using default environment variables (source: {$source})");
        $user->setDetails(['used_custom_env_vars' => false]);
        $user->save();
    }

    /**
     * Whether the checkout's git repository tracks `.env`. No git, or git
     * failing to answer, reads as untracked: the engine's own `.env`, as
     * before ADR-0001.
     *
     * Protected rather than private so a test can force either branch of
     * {@see apply()} without a real git repository — `GitRepository` only
     * runs through a live DinD shell, which a unit test has none of.
     */
    protected function envIsTracked(): bool
    {
        $git = new GitRepository($this->dind);
        if (!$git->hasRepository()) {
            return false;
        }

        try {
            return $git->trackedAmong(['.env']) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Attach `.env.panelalpha` to the run file's services that load `.env`,
     * or detach it from all of them. Rewrites the run file only when that
     * changes it.
     *
     * @return list<string> the services it is attached to
     */
    private function syncRunFileEnvOverrides(bool $attach): array
    {
        $fs = $this->dind->system()->filesystem();
        $runPath = $this->dind->userAppComposeFilePath();
        if (!$fs->fileExists($runPath)) {
            return [];
        }

        $raw = (string) $fs->fileGetContents($runPath);
        $compose = ComposeYaml::parse($raw);
        if ($compose === null) {
            return [];
        }

        if ($attach) {
            [$updated, $services] = ComposeEnvFiles::attach($compose, EngineArtifacts::ENV_OVERRIDES);
        } else {
            [$updated, ] = ComposeEnvFiles::detach($compose, EngineArtifacts::ENV_OVERRIDES);
            $services = [];
        }

        if ($updated !== $compose) {
            $fs->filePutContents(
                $runPath,
                Yaml::dump($updated, 6, 2),
                $this->dind->userModel()->getChownString(),
                '644'
            );
        }

        return $services;
    }

    /**
     * Keys a compose file already answers are the compose file's to answer.
     *
     * A `.env` written here is loaded through `env_file:`, so every key in it
     * becomes a real environment variable -- and a real variable beats
     * `${VAR:-default}` in the compose file, because compose substitutes its
     * default only when the variable is unset **or empty**. So copying a
     * `.env.example` over a compose project silently overrides the choices
     * that project made for its own containers.
     *
     * Lychee is the case that showed it. Its `.env.example` is the
     * *bare-metal* example and says `DB_CONNECTION=sqlite`; its compose file
     * says `DB_CONNECTION: "${DB_CONNECTION:-mysql}"` and ships a MariaDB
     * beside the app. Copying the example pointed Lychee at SQLite, and
     * `DB_DATABASE: "${DB_DATABASE:-lychee}"` then handed SQLite the literal
     * string `lychee` as a file path:
     *
     *     Database file at path [lychee] does not exist.
     *
     * `artisan migrate` failed, the entrypoint's `set -e` killed the
     * container, and it restart-looped -- with a healthy MariaDB running
     * alongside it the whole time. Three deploys failed on it.
     *
     * Only keys written as `${VAR:-default}` are dropped. A bare `${VAR}` has
     * no other source and keeps whatever the example offered, which is what
     * carries a generated `APP_KEY` through. And only a `.env.example` is
     * trimmed: a `.env` the repository actually ships is a real file with a
     * real answer, not a template, and is left exactly as it is.
     *
     * Commented rather than deleted, so the file still says what the project
     * suggested and why it is not in force.
     */
    private function withoutComposeDefaultedKeys(string $contents): string
    {
        $strategy = $this->dind->userModel()->getDeployStrategy();
        if ($strategy !== Strategies::COMPOSE && $strategy !== Strategies::PAEMD) {
            return $contents;
        }

        $fs = $this->dind->system()->filesystem();
        // The run file may not exist yet at this point in a first deploy —
        // read the project's own compose file (or the run file, once a
        // redeploy has produced one and the source no longer applies).
        $composeFile = $this->dind->userAppExistingComposeFilePath() ?? $this->dind->userAppComposeFilePath();
        if (!$fs->fileExists($composeFile)) {
            return $contents;
        }

        [$trimmed, $dropped] = self::deferToComposeDefaults(
            $contents,
            (string) $fs->fileGetContents($composeFile)
        );

        if ($dropped !== []) {
            $this->dind->shell()->logger()?->info(
                'Left to ' . basename($composeFile) . ', which defaults them: ' . implode(', ', $dropped)
            );
        }

        return $trimmed;
    }

    /**
     * The decision itself: an env file and a compose file in, the env file
     * without the keys compose already defaults out.
     *
     * Separated from reading them so the rule can be tested against the two
     * files that produced the failure, with no account and no daemon.
     *
     * @return array{0: string, 1: list<string>} contents, the keys deferred
     */
    public static function deferToComposeDefaults(string $envContents, string $composeRaw): array
    {
        preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):-/', $composeRaw, $matches);
        $defaulted = array_flip($matches[1] ?? []);
        if ($defaulted === []) {
            return [$envContents, []];
        }

        $dropped = [];
        $rows = [];
        foreach (EnvFile::parse($envContents) as $row) {
            $key = ($row['type'] ?? '') === 'variable' ? (string) ($row['key'] ?? '') : '';
            if ($key !== '' && isset($defaulted[$key])) {
                $dropped[] = $key;
                $rows[] = [
                    'type' => 'comment',
                    'text' => '# ' . $key . '= # left to the compose file, which supplies a default',
                ];
                continue;
            }
            $rows[] = $row;
        }

        return $dropped === [] ? [$envContents, []] : [EnvFile::serialise($rows), $dropped];
    }

    /**
     * Blank secrets in a `.env.example` that the engine can fill itself.
     *
     * The generated compose loads `.env` through `env_file:`, so Docker
     * injects every key in it as a real environment variable — and an
     * injected blank beats whatever the image holds. Laravel ships
     * `APP_KEY=`, so the blank shadowed the key `artisan key:generate` wrote
     * during the build, and the app answered 500 on MissingAppKey while the
     * container looked healthy.
     *
     * Filling it here rather than dropping the line: the blank line is a
     * placeholder the framework's own tooling rewrites in place, and a
     * `.env` without it is one `key:generate` silently does nothing to.
     * Filling it also makes the two copies agree, which is what actually
     * matters — the value is written once, into the file that survives
     * redeploys.
     *
     * Whatever the key says, it is replaced -- this only ever reads a
     * `.env.example`, and a key published in a repository's own template is
     * not a key. Firefly III is why the rule is that blunt: its placeholder is
     * exactly 32 characters, so AES-256-CBC accepts it and nothing fails.
     * Every deploy of it would have shared one encryption and session key,
     * printed in public on GitHub, with no error anywhere to say so.
     *
     * An earlier version of this asked whether the value was *usable* and kept
     * it if so. That caught BookStack's 16-character placeholder and let
     * Firefly's straight through, which is the wrong question: the file is a
     * template, so its secrets are everybody's.
     *
     * A project that set its own key is untouched, because a project that set
     * its own key has a `.env`, and this is not reached.
     */
    private static function withGeneratedSecrets(string $contents): string
    {
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable' && ($row['key'] ?? '') === 'APP_KEY') {
                return EnvFile::merge($contents, [
                    'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                ]);
            }
        }

        return $contents;
    }

    /**
     * Signing keys a `.env.example` sets to a well-known placeholder (Saleor's
     * SECRET_KEY=changeme), with the same seed and rule as the compose path.
     * APP_KEY is left to withGeneratedSecrets; keys the account set are left to its env_vars.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the keys replaced
     */
    public static function withoutPublishedSecrets(string $contents, string $seed, array $overrides = []): array
    {
        $generated = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = ($row['type'] ?? '') === 'variable' ? (string) ($row['key'] ?? '') : '';
            if ($key === '' || $key === 'APP_KEY' || isset($overrides[$key])
                || !ComposePlaceholders::isPublishedSecret($key, (string) ($row['value'] ?? ''))
            ) {
                continue;
            }
            $generated[$key] = ComposePlaceholders::publishedSecret($key, $seed);
        }

        return $generated === []
            ? [$contents, []]
            : [EnvFile::merge($contents, $generated), array_keys($generated)];
    }

    /**
     * Blanks in a `.env.example` that were never meant to be a value.
     *
     * The same mechanism as {@see deferToComposeDefaults()} and the opposite
     * direction from {@see withoutPublishedSecrets()}: the copied file reaches
     * the container through `env_file:`, which beats the image's own `ENV`, so
     * a template's fill-in-the-blank silently overrides a working default the
     * image author set.
     *
     * Homarr is the case. Its image ships `ENV DB_URL=/appdata/db/db.sqlite`;
     * its `.env.example` says `DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE`. The
     * copy won, `run.sh` migrated into a file literally called that, the
     * Next.js server chdir'd and opened a different, empty one, and the
     * account restart-looped 1026 times in 35 minutes behind a deploy
     * reported successful.
     *
     * Commented rather than deleted, so the file still says what upstream
     * suggested. Keys the account set itself are left alone -- those are a
     * person's answer, not a template's blank.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the keys left out
     */
    public static function withoutTemplatePlaceholders(string $contents, array $overrides = []): array
    {
        $blanks = [];
        $rows = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = ($row['type'] ?? '') === 'variable' ? (string) ($row['key'] ?? '') : '';
            if ($key !== '' && !isset($overrides[$key])
                && ComposePlaceholders::isTemplatePlaceholder((string) ($row['value'] ?? ''))
            ) {
                $blanks[] = $key;
                $rows[] = [
                    'type' => 'comment',
                    'text' => '# ' . $key . '=' . (string) ($row['value'] ?? '')
                        . ' # a blank in .env.example, left to the image',
                ];
                continue;
            }
            $rows[] = $row;
        }

        return $blanks === [] ? [$contents, []] : [EnvFile::serialise($rows), $blanks];
    }

    private function materializeNestedEnvExamples(string $projectDir, ?string $chown): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $logger = $this->dind->shell()->logger();
        foreach (EnvFile::nestedEnvExampleCopies($projectDir) as $copy) {
            if (is_dir($copy['dest'])) {
                $system->exec(['sudo', 'rm', '-rf', $copy['dest']]);
            }
            $contents = $copy['example'] === ''
                ? ''
                : $fs->fileGetContents($copy['example']);
            $fs->filePutContents($copy['dest'], $contents, $chown, '644');
            $logger?->info(
                $copy['example'] === ''
                    ? 'Created empty ' . $copy['relative'] . ' (required by compose env_file)'
                    : 'Created ' . $copy['relative'] . ' from .env.example'
            );
        }
        $this->materializeComposeEnvFiles($projectDir, $chown);
    }

    /**
     * Compose V2 errors if a service `env_file:` is absent, even when every
     * interpolated value has a `${VAR:-default}`. Repos that tell the operator
     * to `cp .env.example .env` (or ship no example at all) hit this.
     *
     * Both files are read: the one the project brings, and the one the engine
     * runs. A generated run file names `.env` whether or not the project shipped
     * a compose file of its own.
     */
    private function materializeComposeEnvFiles(string $projectDir, ?string $chown): void
    {
        $fs = $this->dind->system()->filesystem();
        $logger = $this->dind->shell()->logger();
        $composePaths = array_unique(array_filter([
            $this->dind->userAppExistingComposeFilePath(),
            $this->dind->userAppComposeFilePath(),
        ]));
        foreach ($composePaths as $composePath) {
            if (!$fs->fileExists($composePath)) {
                continue;
            }
            $raw = $fs->fileGetContents($composePath);
            foreach (EnvFile::composeEnvFileRelativePathsFromYaml($raw) as $relative) {
                $dest = $projectDir . '/' . $relative;
                if ($fs->fileExists($dest)) {
                    continue;
                }
                $fs->filePutContents($dest, '', $chown, '644');
                $logger?->info("Created empty {$relative} (required by compose env_file)");
            }
        }
    }
}
