<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why the engine's own page is being served instead of an application.
 *
 * Two very different situations produce the same page. An account that has
 * never had an application deployed into it is *supposed* to show the welcome
 * page -- that is what it is for, and reporting it as a fault would make every
 * empty account look broken. An account whose ~/project holds files and still
 * shows a placeholder is the real failure: something was uploaded, nothing
 * claimed it, and the fallback recipe wrote over the top.
 *
 * The two are told apart by whether the directory holds anything but the
 * engine's own handwriting, which is the question
 * {@see PlaceholderPage::supersededIndex()} already
 * answers for the deploy pipeline.
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
        foreach (scandir($projectDir) ?: [] as $entry) {
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
