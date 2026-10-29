<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Detect\StaticEntry;
use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why a static site's `/` did not answer with a page.
 *
 * Three shapes, and they need different sentences. The site may have an entry
 * document that is simply gone -- the common one, and the only one where
 * naming the other pages that *are* still there tells the owner what
 * happened. It may have pages but nothing that could be an entry, which is a
 * missing index rather than a missing file. Or it may have no documents at
 * all, which is an upload that did not arrive.
 *
 * Reuses the finders detection itself uses {@see StaticEntry}, {@see HtmlSite},
 * so the entry this names is the entry the next deploy would pick -- an
 * explanation that disagreed with the deploy it describes would send someone
 * looking for the wrong file.
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

        // Documents exist and no entry could be chosen: the deployed config
        // names a file that has since gone.
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
        foreach (scandir($projectDir) ?: [] as $entry) {
            if (preg_match('/\.html?$/i', $entry) === 1 && is_file($projectDir.'/'.$entry)) {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }
}
