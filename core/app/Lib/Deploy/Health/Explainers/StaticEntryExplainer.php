<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Detect\StaticEntry;
use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why a static site's `/` did not answer with a page: no documents, an entry the deploy is not pointed at, or an entry
 * that is gone. Uses detection's own finders ({@see StaticEntry}, {@see HtmlSite}), so it names the file the next deploy would pick.
 */
final class StaticEntryExplainer implements Explainer
{
    /** Pages named in a sentence before it becomes a list nobody reads. */
    private const NAMED = 3;

    public function id(): string
    {
        return 'static-entry';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        $documents = self::documents($context->projectDir);

        if ($documents === []) {
            return [
                'detail' => 'There is no HTML document in ~/project at all.',
                'fix' => 'Upload the site\'s files into ~/project and redeploy.',
            ];
        }

        $entry = HtmlSite::entry($context->projectDir)
            ?? StaticEntry::find($context->projectDir, $context->files);

        if ($entry !== null) {
            return [
                'detail' => 'The site should be served from '.$entry.', which is in ~/project, '
                    .'so the deployed configuration is pointing somewhere else.',
                'fix' => 'Redeploy the project: the running nginx configuration predates the files on disk.',
            ];
        }

        return [
            'detail' => self::listing($documents).' still in ~/project, but the document the '
                .'deployed site is served from is not.',
            'fix' => 'Restore that file, or rename one of the pages above to index.html and redeploy.',
        ];
    }

    /**
     * @param  list<string>  $documents
     */
    private static function listing(array $documents): string
    {
        $shown = array_slice($documents, 0, self::NAMED);
        $rest = count($documents) - count($shown);
        $names = implode(', ', $shown);

        if ($rest > 0) {
            return $names.' and '.$rest.' more page'.($rest === 1 ? '' : 's').' are';
        }

        return count($shown) === 1 ? $names.' is' : $names.' are';
    }

    /**
     * Root documents, which is where a front page would be. A deeper walk
     * would name build output as though it were the site.
     *
     * @return list<string>
     */
    private static function documents(string $projectDir): array
    {
        $found = [];
        foreach (ProjectContext::entries($projectDir) as $entry) {
            if (preg_match('/\.html?$/i', $entry) === 1 && is_file($projectDir.'/'.$entry)) {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }
}
