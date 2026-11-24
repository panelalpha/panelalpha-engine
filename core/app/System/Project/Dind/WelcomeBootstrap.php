<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Models\User;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;

/**
 * Welcome page and static nginx compose for an account with no sources yet.
 */
final class WelcomeBootstrap
{
    public function __construct(
        private DindProject $project,
        private Paths $paths,
    ) {}

    public function writeIfNeeded(): void
    {
        $appDir = $this->paths->appDir();
        if ($this->paths->existingComposeFile() !== null) {
            return;
        }
        if ($this->project->userModel()->hasGitProject()) {
            return;
        }

        $this->writeWelcomeSite($appDir);
    }

    /** A container project with no repository that has deployed nothing: the welcome page is what it shows. */
    public static function waitsForFiles(User $user): bool
    {
        return $user->getTemplate() === 'dind' && ! $user->hasGitProject() && $user->getDeployStrategy() === null;
    }

    /** Have the welcome site's run file load `.env` optionally, as {@see DeployCompose::staticNginx()} writes it. */
    public function refreshRunFile(): void
    {
        $path = $this->paths->composeFile();
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($path) || $fs->isSymlink($path)) {
            return;
        }
        $raw = $fs->fileGetContents($path);
        $compose = ComposeYaml::parse($raw);
        if ($compose === null || ! self::isWelcomeSite($compose)) {
            return;
        }
        [$optional, $changed] = ComposeEnvFiles::madeOptional($compose, '.env');
        if ($changed) {
            $owner = implode(':', $this->project->userModel()->linuxIds());
            $fs->writeFileReplacingPath($path, ComposeYaml::dump($optional, $raw, 6, 2), $owner, '644');
        }
    }

    /**
     * @param  array<mixed>  $compose
     */
    private static function isWelcomeSite(array $compose): bool
    {
        $labels = $compose['services'][GeneratedCompose::APP_SERVICE]['labels'] ?? null;

        return is_array($labels) && ($labels[GeneratedCompose::LABEL] ?? null) === DetectProjectStrategy::COMPOSE_GENERATED_LABEL;
    }

    /** Root makes ~/project for the account, which writes every file in it. */
    private function writeWelcomeSite(string $appDir): void
    {
        $owner = implode(':', $this->project->userModel()->linuxIds());
        $system = $this->project->system();
        $fs = $system->filesystem();
        $system->exec(Privileged::scripts()->makeHomeEntry($appDir, '0755', $owner));
        $fs->writeFileReplacingPath(
            $this->paths->composeFile(),
            DeployCompose::staticNginx(),
            $owner,
            '644'
        );
        $fs->writeFileReplacingPath(
            $appDir.'/'.NginxConfig::FILENAME,
            NginxConfig::site(null),
            $owner,
            '644'
        );
        $fs->writeFileReplacingPath(
            "{$appDir}/index.html",
            self::html(),
            $owner,
            '644'
        );
    }

    public static function html(): string
    {
        $title = PlaceholderPage::WELCOME_TITLE;
        $body = self::welcomeBody();

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
            <h2>Your environment is ready</h2>
        {$body}
        </body>
        </html>
        HTML;
    }

    private static function welcomeBody(): string
    {
        return <<<'HTML'
            <p>
                PanelAlpha has set up your container environment. You can now log in and place your
                application files in the <code>~/project</code> directory.
            </p>
            <p>
                When you are ready, create <code>~/project/docker-compose.yml</code> with your
                application's configuration, then start the project again (the <code>up</code> action).
            </p>
        HTML;
    }
}
