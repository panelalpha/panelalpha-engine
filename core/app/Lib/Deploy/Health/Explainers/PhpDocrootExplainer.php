<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why PHP was served as text rather than run.
 *
 * The answer is nearly always the document root, and where the project keeps
 * its entry point says which way it is wrong. A `public/index.php` served from
 * the project root gives exactly this -- the interpreter is fine, the
 * webserver is looking one directory above the application -- and an
 * `index.php` at the root that is not being run is the opposite problem.
 *
 * Naming the file that exists is what turns "PHP is not running" into
 * something someone can act on without opening a shell.
 */
final class PhpDocrootExplainer implements Explainer
{
    /**
     * Where PHP applications keep their entry point, in the order worth
     * reporting: an application that has both is served from `public`.
     *
     * @var list<string>
     */
    private const ENTRY_POINTS = ['public/index.php', 'pub/index.php', 'web/index.php', 'html/index.php', 'index.php'];

    public function id(): string
    {
        return 'php-docroot';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        foreach (self::ENTRY_POINTS as $entry) {
            if (!$context->isFile($entry)) {
                continue;
            }

            return str_contains($entry, '/')
                ? [
                    'detail' => 'The entry point is ' . $entry . ', so the document root should be '
                        . dirname($entry) . ' rather than the project root.',
                    'fix' => PhpEntryExplainer::docrootFix($context, dirname($entry)),
                ]
                : [
                    'detail' => 'index.php is at the project root and is being served rather than run.',
                    'fix' => 'The container is not handing .php files to the interpreter; redeploy the project.',
                ];
        }

        return [
            'detail' => 'No index.php was found in the usual places, so what is being served is some other PHP file.',
            'fix' => 'Check that the application\'s entry point is where the platform expects it.',
        ];
    }
}
