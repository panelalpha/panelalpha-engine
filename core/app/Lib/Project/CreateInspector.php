<?php

namespace App\Lib\Project;

use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Platform\DeployPlan;

/**
 * The inspection POST /source/inspect runs in its tree mode, run by a create
 * before anything is provisioned: seconds for a public github.com repository,
 * and skipped for every other source rather than paid for with a second clone.
 */
class CreateInspector
{
    /** Seconds the whole read may take before the create goes on without it. */
    public const SECONDS = 20;

    private SourceResolver $resolver;

    public function __construct(?SourceResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new SourceResolver(
            storage_path('app/source-inspect'),
            self::SECONDS,
            new GitHubTree(null, self::SECONDS)
        );
    }

    public function inspect(
        string $repoUrl,
        ?string $branch = null,
        ?string $token = null,
        ?string $recipe = null,
        ?DeployPlan $plan = null
    ): CreateInspection {
        if ($token !== null && trim($token) !== '') {
            return CreateInspection::skipped('it is cloned with a token, and only a public repository is read from its file list.');
        }
        $repoUrl = SourceResolver::normaliseGitUrl($repoUrl);
        if (GitHubTree::repository($repoUrl) === null) {
            return CreateInspection::skipped('only a public github.com repository is read from its file list.');
        }

        $branch = $branch === null || trim($branch) === '' ? null : trim($branch);
        try {
            $resolved = $this->resolver->fromGitHubTree($repoUrl, $branch);
        } catch (\Throwable $e) {
            return CreateInspection::skipped('its file list could not be read (' . self::brief($e) . ').');
        }

        try {
            // As the inspect endpoint does: the deploy lays a recipe's files
            // over the checkout before detection.
            RecipeFileOverlay::apply($resolved->dir, $repoUrl);

            return CreateInspection::fromReport(AppInspector::inspect($resolved->dir, $repoUrl, $plan, $recipe));
        } catch (\Throwable $e) {
            return CreateInspection::skipped('inspecting its file list failed (' . self::brief($e) . ').');
        } finally {
            $resolved->release();
        }
    }

    private static function brief(\Throwable $e): string
    {
        return rtrim(mb_substr(trim($e->getMessage()), 0, 200), '.');
    }
}
