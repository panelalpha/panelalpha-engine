<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Which script is running, when what is answering is a development server.
 *
 * The check can tell that a dev server is serving the page; only package.json
 * can say which line to change. "This project is being served by a
 * development server" sends someone looking; "package.json's start script is
 * `next dev`" is the edit.
 */
final class NodeStartCommandExplainer implements Explainer
{
    public function id(): string
    {
        return 'node-start-command';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        $scripts = $context->package()['scripts'] ?? null;
        $start = is_array($scripts) && is_string($scripts['start'] ?? null) ? trim($scripts['start']) : null;

        if ($start === null) {
            return [
                'fix' => 'Build the project and start it with the production command '
                    .'(`next start`, `vite preview`, `node dist/server.js`) rather than its dev script.',
            ];
        }

        $build = is_array($scripts) && is_string($scripts['build'] ?? null) ? trim($scripts['build']) : null;

        return [
            'detail' => 'package.json starts this project with `'.$start.'`.',
            'fix' => $build !== null
                ? 'Point the start stage at the production server; this project already builds with `'.$build.'`.'
                : 'Point the start stage at a production server rather than the dev one.',
        ];
    }
}
