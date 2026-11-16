<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why the engine's own page is served: an empty account is supposed to show it, while files nothing claimed are the real
 * failure. Told apart by the engine's own scaffolding, as {@see PlaceholderPage::supersededIndex()} does for the pipeline.
 */
final class PlaceholderPageExplainer implements Explainer
{
    public function id(): string
    {
        return 'placeholder-page';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        $files = self::projectFiles($context->projectDir);

        if ($files === 0) {
            return [
                'detail' => '~/project is empty, so this is the welcome page a new account starts with.',
                'fix' => 'Deploy an application into the project.',
            ];
        }

        return [
            'detail' => '~/project holds '.$files.' file'.($files === 1 ? '' : 's')
                .', so an application was uploaded and no recipe recognised it.',
            'fix' => 'Check the deploy log for the detected project type. A type of "Unknown" '
                .'means nothing matched and the engine served its own page instead.',
        ];
    }

    /** Entries in ~/project that are not the engine's own scaffolding. */
    private static function projectFiles(string $projectDir): int
    {
        $count = 0;
        foreach (ProjectContext::entries($projectDir) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (! PlaceholderPage::isScaffolding($projectDir, $entry)) {
                $count++;
            }
        }

        return $count;
    }
}
