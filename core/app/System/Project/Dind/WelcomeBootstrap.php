<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\System\Project\Dind as DindProject;

/**
 * Welcome page and static nginx compose for an account with no sources yet.
 */
final class WelcomeBootstrap
{
    public function __construct(
        private DindProject $project,
        private Paths $paths,
    ) {
    }

    public function writeIfNeeded(): void
    {
        $appDir = $this->paths->appDir();
        if ($this->paths->existingComposeFile() !== null) {
            return;
        }
        if ($this->project->userModel()->hasGitProject()) {
            return;
        }

        $system = $this->project->system();
        $fs = $system->filesystem();
        $system->exec(['sudo', 'mkdir', '-p', $appDir]);
        $fs->filePutContents(
            $this->paths->composeFile(),
            DeployCompose::staticNginx(),
            null,
            '644'
        );
        $fs->filePutContents(
            $appDir . '/' . NginxConfig::FILENAME,
            NginxConfig::site(null),
            null,
            '644'
        );
        $fs->filePutContents(
            "{$appDir}/index.html",
            $this->welcomeHtml(),
            null,
            '644'
        );
    }

    private function welcomeHtml(): string
    {
        $title = PlaceholderPage::WELCOME_TITLE;
        $body = <<<HTML
            <p>
                PanelAlpha has set up your container environment. You can now log in and place your
                application files in the <code>~/project</code> directory.
            </p>
            <p>
                When you are ready, create <code>~/project/docker-compose.yml</code> with your
                application's configuration, then start the project again (the <code>up</code> action).
            </p>
        HTML;

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
}
