<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Template\Template;

/**
 * The layer that turns a lockfile into node_modules.
 *
 * Manifests are copied ahead of the source so BuildKit can reuse the install
 * when only application code changed. Workspaces are the exception: npm and bun
 * need every workspace `package.json` before they can link, so those copy the
 * tree first and give the cache up. So does a root install script.
 */
final class NodeInstallLayer
{
    private const MANIFESTS = ['bunfig.toml', '.npmrc', 'pnpm-workspace.yaml'];

    public function __construct(private readonly BuildRecipe $recipe, private readonly string $installCommand)
    {
    }

    /**
     * The flag goes between `RUN` and the command, which is where the template
     * interpolates this string.
     */
    private function install(): string
    {
        return PackageManagerCache::mountFor($this->recipe->packageManager) . $this->installCommand;
    }

    public function render(): string
    {
        return $this->recipe->isWorkspace() || $this->runsRootLifecycleScript()
            ? $this->wholeTree()
            : $this->manifestsFirst();
    }

    /**
     * A root install script the strip keeps runs during the install and may
     * read the repository (teikei's `lerna exec` needs lerna.json, jitsi-meet's
     * patch-package needs patches/), so it needs the tree as workspaces do.
     */
    private function runsRootLifecycleScript(): bool
    {
        $scripts = $this->recipe->package()['scripts'] ?? null;
        if (!is_array($scripts)) {
            return false;
        }
        foreach (JsPackageManager::INSTALL_LIFECYCLE_SCRIPTS as $name) {
            $script = $scripts[$name] ?? null;
            if (is_string($script) && trim($script) !== ''
                && !JsPackageManager::isGitHookInstallerScript($script, sourcePresent: true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function wholeTree(): string
    {
        return Template::named('dockerfile/node-install-workspace')->render([
            // `COPY . .` comes first here, so a lifecycle script can run a
            // repository file: only the git-hook tools are stripped.
            'strip_git_hooks' => $this->stripGitHooks(sourcePresent: true),
            'install_command' => $this->install(),
        ]);
    }

    private function manifestsFirst(): string
    {
        return Template::named('dockerfile/node-install')->render([
            'manifest_copies' => $this->manifestCopies(),
            // Only package.json and the lockfile are present, so a script
            // naming a repository file cannot run.
            'strip_git_hooks' => $this->stripGitHooks(sourcePresent: false),
            'install_command' => $this->install(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function manifestCopies(): array
    {
        $copy = Template::named('dockerfile/copy-into-workdir');

        return array_map(
            static fn (string $path): string => rtrim($copy->render(['path' => $path]), "\n"),
            $this->copiedManifests()
        );
    }

    /**
     * @return list<string>
     */
    private function copiedManifests(): array
    {
        $lockfile = JsPackageManager::lockfileName($this->recipe->packageManager, $this->recipe->files);
        $manifests = $lockfile === null ? [] : [$lockfile];

        foreach (self::MANIFESTS as $name) {
            if ($this->recipe->hasFile($name)) {
                $manifests[] = $name;
            }
        }

        // A file the project's .dockerignore drops is not in the build
        // context, and COPYing it fails the whole build.
        return array_values(array_filter(
            $manifests,
            fn (string $name): bool => !DockerIgnore::excludes($this->recipe->projectDir, $name)
        ));
    }

    private function stripGitHooks(bool $sourcePresent): string
    {
        return JsPackageManager::dockerfileStripGitHookScriptsCommand(
            $this->recipe->packageManager,
            $sourcePresent
        );
    }
}
