<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\Probes\ProcfileWebProbe;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\System\Project\Dind as DindProject;

/**
 * Writes the inner compose file {@see DeployStrategy} decided on, plus the
 * placeholder index.html pages shown before a real app is deployed.
 */
class ComposeWriter
{
    private DindProject $project;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    public function writeGeneratedCompose(string $projectDir, string $yaml, ?string $chown): void
    {
        $paths = $this->project->userModel()->getPersistPaths();
        if ($paths !== []) {
            $yaml = GeneratedCompose::withPersistedPaths($yaml, $paths);
            $this->project->shell()->logger()?->info('Keeping across deploys: '.implode(', ', $paths));
        }
        $procfile = $this->project->projectTree()->readIn($projectDir, 'Procfile');
        $processes = $procfile === null ? [] : ProcfileWebProbe::otherProcesses($procfile);
        $withProcesses = GeneratedCompose::withProcesses($yaml, $processes);
        if ($withProcesses !== $yaml) {
            $yaml = $withProcesses;
            $this->project->shell()->logger()?->info('Running the Procfile processes too: '.implode(', ', array_keys($processes)));
        }
        $yaml = $this->withDeclaredPorts($yaml);
        $procFiles = LxcfsProc::inAccount($this->project);
        $parsed = $procFiles === [] ? null : ComposeYaml::parse($yaml);
        if ($parsed !== null) {
            $yaml = ComposeYaml::dump(ComposeHarden::withProcMounts($parsed, $procFiles), $yaml, 6, 2);
        }
        $this->writeRunFile($this->project->userAppComposeFilePath(), $yaml, $chown);
        $this->freezeRuntimeImage($yaml);
    }

    private function withDeclaredPorts(string $yaml): string
    {
        $parsed = ProjectPublicRouting::declared($this->project->userModel())->ports === [] ? null : ComposeYaml::parse($yaml);
        if ($parsed === null) {
            return $yaml;
        }
        $published = ProjectPublicRouting::publishDeclaredPorts($this->project->userModel(), $parsed, $this->project->shell()->logger());

        return $published === $parsed ? $yaml : ComposeYaml::dump($published, $yaml, 6, 2);
    }

    /**
     * Point the generated run file's `environment:` at the account's current env_vars: it bakes them in, and
     * `environment:` outranks `env_file:`. A file the repository owns reads `.env` and is left alone.
     *
     * @param  array<string, string>  $vars  the account's current env_vars
     * @param  list<string>  $replaced  the keys the file was generated with
     * @return bool whether the run file changed
     */
    public function mergeAccountEnv(array $vars, array $replaced): bool
    {
        $run = $this->generatedRunFile();
        if ($run === null) {
            return false;
        }
        [$path, $raw, $compose, $environment] = $run;
        ProjectEnvironment::recordServiceKeys($this->project->userModel(), self::accountKeys($vars));
        $merged = self::withAccountEnv($environment, $vars, $replaced);
        if ($merged === null) {
            return false;
        }
        $compose['services'][GeneratedCompose::APP_SERVICE]['environment'] = $merged;
        $this->writeRunFile($path, ComposeYaml::dump($compose, $raw, 6, 2), $this->project->userModel()->getChownString());

        return true;
    }

    /** Replaces whatever sits at $path, so a symlink a tenant planted there is never written through. */
    private function writeRunFile(string $path, string $yaml, ?string $chown): void
    {
        $this->project->system()->filesystem()->writeFileReplacingPath($path, $yaml, $chown, EngineArtifacts::RUN_COMPOSE_MODE);
    }

    /**
     * The run file the engine generated, with its app service's `environment:` map; null for any other file.
     *
     * @return ?array{0: string, 1: string, 2: array<mixed>, 3: array<mixed>} path, raw text, parsed file, environment
     */
    private function generatedRunFile(): ?array
    {
        $path = $this->project->userAppComposeFilePath();
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($path)) {
            return null;
        }
        $fs->assertNotSymlink($path);
        $raw = (string) $fs->fileGetContents($path);
        $compose = ComposeYaml::parse($raw);
        $service = $compose !== null && self::isEngineGenerated($compose) ? ($compose['services'][GeneratedCompose::APP_SERVICE] ?? null) : null;
        $environment = is_array($service) ? ($service['environment'] ?? []) : null;
        if (! is_array($environment) || self::isListStyleEnvironment($environment)) {
            return null;
        }

        return [$path, $raw, $compose, $environment];
    }

    /**
     * @param  array<string, string>  $vars
     * @return list<string> the keys {@see withAccountEnv()} sets
     */
    private static function accountKeys(array $vars): array
    {
        return array_values(array_filter(array_map('strval', array_keys($vars)), static fn (string $key): bool => ! ComposeEnvironment::isReserved($key)));
    }

    /**
     * @param  array<mixed>  $compose
     */
    private static function isEngineGenerated(array $compose): bool
    {
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $service) {
            if (is_array($service) && array_key_exists(GeneratedCompose::LABEL, (array) ($service['labels'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A non-empty list is the `- KEY=value` form and is not ours to write into; an empty list is
     * indistinguishable from an absent map after parsing, and is read as one.
     *
     * @param  array<mixed>  $environment
     */
    private static function isListStyleEnvironment(array $environment): bool
    {
        return $environment !== [] && array_is_list($environment);
    }

    /**
     * The engine's own variables and the ones that name where the app is
     * published are never the account's to replace.
     *
     * @param  array<mixed>  $environment
     * @param  array<string, string>  $vars
     * @param  list<string>  $replaced
     * @return ?array<mixed> null when nothing changed
     */
    private static function withAccountEnv(array $environment, array $vars, array $replaced): ?array
    {
        $before = $environment;
        foreach ($replaced as $key) {
            if (! ComposeEnvironment::isReserved($key)) {
                unset($environment[$key]);
            }
        }
        foreach ($vars as $key => $value) {
            if (! ComposeEnvironment::isReserved($key)) {
                $environment[$key] = self::escapedForCompose((string) $value);
            }
        }

        return $environment === $before ? null : $environment;
    }

    /**
     * Point the generated run file's `environment:` at the engine's API address, token and CA, or take them out
     * when the project is not allowed. A repository's own file reads `.env.panelalpha`, already refreshed.
     *
     * @param  array<string, string>  $values
     * @param  ?string  $path  a run file other than the project's own
     * @return bool whether the run file changed
     */
    public function mergeApiAccessEnv(array $values, ?string $path = null): bool
    {
        $path ??= $this->project->userAppComposeFilePath();
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($path)) {
            return false;
        }
        $fs->assertNotSymlink($path);
        $raw = (string) $fs->fileGetContents($path);
        $compose = ComposeYaml::parse($raw);
        if ($compose === null || ! self::isEngineGenerated($compose)) {
            return false;
        }
        $service = $compose['services'][GeneratedCompose::APP_SERVICE] ?? null;
        if (! is_array($service)) {
            return false;
        }
        $environment = $service['environment'] ?? [];
        if (! is_array($environment) || self::isListStyleEnvironment($environment)) {
            return false;
        }
        $merged = self::withApiAccessEnv($environment, $values);
        if ($merged === null) {
            return false;
        }
        $compose['services'][GeneratedCompose::APP_SERVICE]['environment'] = $merged;
        $this->writeRunFile($path, ComposeYaml::dump($compose, $raw, 6, 2), $this->project->userModel()->getChownString());

        return true;
    }

    /**
     * @param  array<mixed>  $environment
     * @param  array<string, string>  $values
     * @return ?array<mixed> null when nothing changed
     */
    private static function withApiAccessEnv(array $environment, array $values): ?array
    {
        $before = $environment;
        $escaped = ComposeEnvironment::escapedForCompose($values);
        foreach (ApiAccess::ENV_KEYS as $key) {
            if (! array_key_exists($key, $escaped)) {
                unset($environment[$key]);
            }
        }
        foreach ($escaped as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment === $before ? null : $environment;
    }

    /**
     * Compose interpolates `$` in an `environment:` value, so a literal one the
     * account set reaches the container only when it is doubled.
     */
    private static function escapedForCompose(string $value): string
    {
        return str_replace('$', '$$', $value);
    }

    /**
     * Point the app service at another image, after the host compile had to build with it: the run file was
     * written before the compile. False when the file does not name $from as the app's image.
     */
    public function replaceAppImage(string $from, string $to, ?string $chown): bool
    {
        $path = $this->project->userAppComposeFilePath();
        $yaml = $this->project->system()->filesystem()->fileGetContents($path);
        if (GeneratedCompose::appImage($yaml) !== $from) {
            return false;
        }
        $replaced = preg_replace(
            '/^(\s*image:\s*)([\'"]?)'.preg_quote($from, '/').'\2\s*$/m',
            '${1}'."'".$to."'",
            $yaml
        );
        if (! is_string($replaced) || GeneratedCompose::appImage($replaced) !== $to) {
            return false;
        }
        $this->writeGeneratedCompose(dirname($path), $replaced, $chown);

        return true;
    }

    /**
     * The nginx config of {@see DeployCompose::staticNginx()}, which every caller writes, the fallback too: the file
     * mounts it unconditionally, and a missing source is a directory Docker creates and nginx refuses to read.
     *
     * @param  ?string  $entry  the document `/` serves, relative to the project root; null leaves
     *                          index.html, which is what the placeholder pages are called
     */
    public function writeStaticNginxConf(string $projectDir, ?string $entry, ?string $chown): void
    {
        $this->project->system()->filesystem()->writeFileReplacingPath(
            $projectDir.'/'.NginxConfig::FILENAME,
            NginxConfig::site($entry),
            $chown,
            '644'
        );
    }

    /** {@see NginxConfig::placeholder()}: the not-configured page on every path, never the checkout. */
    public function writePlaceholderNginxConf(string $projectDir, ?string $chown): void
    {
        $this->project->system()->filesystem()->writeFileReplacingPath(
            $projectDir.'/'.NginxConfig::FILENAME,
            NginxConfig::placeholder(),
            $chown,
            '644'
        );
    }

    /**
     * `deploy_image` from the compose file `up` obeys, as AppLauncher seeds it by name and {@see SourcePreparation}
     * froze it before a strategy swapped the image. Unparseable leaves it: a slightly stale snapshot beats a guess.
     */
    private function freezeRuntimeImage(string $yaml): void
    {
        $image = GeneratedCompose::appImage($yaml);
        if ($image === null) {
            return;
        }

        $this->project->freezeDeploySnapshot(['deploy_image' => $image]);
    }

    /**
     * Placeholder shown after the container environment is provisioned but
     * before the user has supplied an application.
     */
    public function writeWelcomeIndexHtml(string $appDir): void
    {
        $body = <<<'HTML'
            <p>
                PanelAlpha has set up your container environment. You can now log in and place your
                application files in the <code>~/project</code> directory.
            </p>
            <p>
                When you are ready, create <code>~/project/docker-compose.yml</code> with your
                application's configuration, then start the project again (the <code>up</code> action).
            </p>
        HTML;

        $this->project->system()->filesystem()->writeFileReplacingPath(
            "{$appDir}/index.html",
            self::infoPage(PlaceholderPage::WELCOME_TITLE, 'Your environment is ready', $body),
            null,
            '644'
        );
    }

    /**
     * Placeholder shown when sources were ingested but no strategy could
     * bootstrap them. Never overwrites a real page the user shipped.
     *
     * @return bool false when the user's own index.html is the front page
     */
    public function writeFallbackIndexHtml(string $projectDir, string $sourceDescription, ?string $chown): bool
    {
        $index = $projectDir.'/index.html';
        if (is_file($index) && ! PlaceholderPage::isOneOf($index)) {
            return false;
        }

        $sourceHtml = self::sourceHtml($sourceDescription);

        $body = <<<HTML
            <p>
                Your project ({$sourceHtml})
                does not contain a <code>docker-compose.yml</code> and PanelAlpha Engine could not
                determine how to bootstrap it automatically.
            </p>
            <p>
                To get started, log in to your account and prepare <code>docker-compose.yml</code>
                in the <code>~/project</code> directory, then deploy the project again.
            </p>
        HTML;

        $this->project->system()->filesystem()->writeFileReplacingPath(
            $index,
            self::infoPage(PlaceholderPage::NOT_CONFIGURED_TITLE, 'Project not ready yet', $body),
            $chown,
            '644'
        );

        return true;
    }

    private static function sourceHtml(string $sourceDescription): string
    {
        $safeSource = htmlspecialchars($sourceDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_match('#^https?://#i', $sourceDescription) === 1
            ? "<a href=\"{$safeSource}\" target=\"_blank\" rel=\"noopener\">{$safeSource}</a>"
            : "<code>{$safeSource}</code>";
    }

    /**
     * Shared chrome for the two placeholder pages. The title is load-bearing: PlaceholderPage::isOneOf() matches
     * on it to tell an engine placeholder apart from a page the user actually shipped.
     */
    private static function infoPage(string $title, string $heading, string $body): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                body { font-family: sans-serif; max-width: 640px; margin: 80px auto; padding: 0 20px; color: #333; }
                code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
                a { color: #0070f3; }
            </style>
        </head>
        <body>
            <h2>{$heading}</h2>
        {$body}
        </body>
        </html>
        HTML;
    }
}
