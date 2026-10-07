<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * A usable compose file in one of {@see NestedCompose::DIRECTORIES}, for a
 * repository that has nothing else to deploy: zoraxy and WikiDocs keep theirs
 * under docker/ and used to land on the placeholder page.
 *
 * Only a project no platform and no Railpack runtime claims: its manifest sits
 * below every other one, and a runtime Railpack recognises vetoes it. So it
 * cannot take a repository that deploys some other way today.
 *
 * The same filters as the root probe, applied to the file as it reads from the
 * root. Yields `compose_path` or false.
 */
final class ComposeUsableNestedProbe implements PlatformProbe
{
    /** Words in a directory name that mean its compose file is not the deployment. */
    private const NOT_A_DEPLOYMENT = [
        'test', 'tests', 'e2e', 'ci', 'dev', 'devcontainer', 'local', 'doc', 'docs',
        'example', 'examples', 'sample', 'samples', 'demo', 'fixtures', 'benchmark', 'benchmarks',
    ];

    public function id(): string
    {
        return 'compose-usable-nested';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        if (RuntimeRegistry::anyRecognises($context)) {
            return false;
        }

        foreach (NestedCompose::DIRECTORIES as $directory) {
            if (!$context->hasFile($directory) || !is_dir($context->path($directory))) {
                continue;
            }
            $found = $this->usableIn($directory, $context);
            if ($found !== null) {
                return ['compose_path' => $context->path($found)];
            }
        }

        // Any other top-level directory, when it is the only one holding a usable
        // file (autobase keeps its stack in console/). Two make it a guess.
        $found = [];
        foreach ($this->otherDirectories($context) as $directory) {
            $relative = $this->usableIn($directory, $context);
            if ($relative !== null) {
                $found[] = $relative;
            }
        }

        return count($found) === 1 ? ['compose_path' => $context->path($found[0])] : false;
    }

    private function usableIn(string $directory, ProjectContext $context): ?string
    {
        foreach (ComposeFileInspector::COMPOSE_FILE_CANDIDATES as $candidate) {
            $relative = $directory . '/' . $candidate;
            if ($context->isFile($relative) && $this->usable($relative, $context)) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * Top-level directories outside the list, less the ones whose name says
     * they hold tests, examples, docs or a dev setup (huly's `ws-tests/`).
     *
     * @return list<string>
     */
    private function otherDirectories(ProjectContext $context): array
    {
        $entries = @scandir($context->projectDir);
        $directories = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (str_starts_with($entry, '.') || in_array($entry, NestedCompose::DIRECTORIES, true)
                || !is_dir($context->path($entry))
            ) {
                continue;
            }
            $words = preg_split('/[^a-z0-9]+/', strtolower($entry)) ?: [];
            if (array_intersect($words, self::NOT_A_DEPLOYMENT) === []) {
                $directories[] = $entry;
            }
        }

        return $directories;
    }

    private function usable(string $relative, ProjectContext $context): bool
    {
        $path = $context->path($relative);
        $raw = $context->contents($relative);
        $parsed = is_string($raw) ? ComposeYaml::parse($raw) : null;
        if (!is_array($parsed) || !is_array($parsed['services'] ?? null)) {
            return false;
        }
        $fromRoot = Yaml::dump(NestedCompose::rebase($parsed, dirname($relative)), 6, 2);

        if (ComposeFileInspector::isGeneratedBootstrapCompose($path)
            || ComposeFileInspector::isLocalDevComposeYaml($fromRoot)
            || ComposeFileInspector::isSidecarsOnlyComposeYaml($fromRoot)
            || ComposeFileInspector::startsNothingReasonYaml($fromRoot) !== null
        ) {
            return false;
        }

        return ComposeFileInspector::missingComposeDockerfileRefs($path, $context->projectDir) === [];
    }
}
