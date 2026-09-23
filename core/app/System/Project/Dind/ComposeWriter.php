<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Detect\PlaceholderPage;
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
        $this->project->system()->filesystem()->filePutContents(
            $this->project->userAppComposeFilePath(),
            $yaml,
            $chown,
            '644'
        );
        $this->freezeRuntimeImage($yaml);
    }

    /**
     * The nginx config that goes with {@see DeployCompose::staticNginx()}.
     *
     * Written by every caller of that compose file, including the fallback
     * recipe whose entry really is index.html: the compose file mounts the
     * config unconditionally, and a mount whose source does not exist is a
     * directory Docker creates and nginx then refuses to read.
     *
     * `$entry` is the document `/` should serve, relative to the project
     * root. Null leaves nginx looking for index.html, which is what the
     * placeholder pages are called.
     */
    public function writeStaticNginxConf(string $projectDir, ?string $entry, ?string $chown): void
    {
        $this->project->system()->filesystem()->filePutContents(
            $projectDir . '/' . NginxConfig::FILENAME,
            NginxConfig::site($entry),
            $chown,
            '644'
        );
    }

    /**
     * Correct the deploy snapshot to the image the account will actually run.
     *
     * {@see SourcePreparation} freezes `deploy_image` from the detection
     * decision, before a strategy swaps in the shared base, the lockfile's
     * interpreter or the Bun image. `AppLauncher` provisions
     * `getDeployImage()` by name, so a stale snapshot seeds an image nothing
     * runs. The compose file is the authority — it is what `compose up` obeys.
     *
     * Silent on anything unparseable: a slightly stale snapshot beats one
     * overwritten with a guess.
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

        $this->project->system()->filesystem()->filePutContents(
            "{$appDir}/index.html",
            self::infoPage(PlaceholderPage::WELCOME_TITLE, 'Your environment is ready', $body),
            null,
            '644'
        );
    }

    /**
     * Placeholder shown when sources were ingested but no strategy could
     * bootstrap them. Never overwrites a real page the user shipped.
     */
    public function writeFallbackIndexHtml(string $projectDir, string $sourceDescription, ?string $chown): void
    {
        $index = $projectDir . '/index.html';
        if (is_file($index) && !PlaceholderPage::isOneOf($index)) {
            return;
        }

        $safeSource = htmlspecialchars($sourceDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceHtml = preg_match('#^https?://#i', $sourceDescription) === 1
            ? "<a href=\"{$safeSource}\" target=\"_blank\" rel=\"noopener\">{$safeSource}</a>"
            : "<code>{$safeSource}</code>";

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

        $this->project->system()->filesystem()->filePutContents(
            $index,
            self::infoPage(PlaceholderPage::NOT_CONFIGURED_TITLE, 'Project not ready yet', $body),
            $chown,
            '644'
        );
    }

    /**
     * Shared chrome for the two placeholder pages. The title is load-bearing:
     * PlaceholderPage::isOneOf() matches on it to tell an
     * engine placeholder apart from a page the user actually shipped.
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
