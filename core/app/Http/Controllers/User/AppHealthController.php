<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\System\Project\Dind;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use OpenApi\Attributes as OA;

/**
 * Is the deployed application answering?
 *
 * Thin wrapper over {@see \App\System\Project\Dind\AppHealth} —
 * the same probe the deploy pipeline runs at the end of every deploy, exposed
 * so the panel can show app status without tailing a deploy log.
 */
class AppHealthController extends Controller
{
    private function getDind(string $username): Dind
    {
        $user = $this->projectOr404($username, 'Not found');
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'App health checks are only available for dind users'], 403));
        }

        $runtime = $user->project()->runtime();
        if (!$runtime instanceof Dind) {
            abort(new JsonResponse(['message' => 'App health checks are only available for dind users'], 403));
        }

        return $runtime;
    }

    #[OA\Get(
        path: '/projects/{username}/app/health',
        summary: 'Check that the deployed application answers on its published ports',
        description: 'Probes each port the application publishes from inside the account container, over loopback. '
            . 'Does not go through the domain, DNS, TLS or the reverse proxy, so a failure here means the application '
            . 'itself is not answering. `healthy` is null when the application publishes no port to probe. '
            . '`checks` answers a different question from `healthy`: not whether something answered, but whether what '
            . 'answered is the application - a project the engine could not recognise is served the engine\'s own '
            . 'placeholder page with a 200, and a static site that has lost its front page answers 404 on `/` while '
            . 'every other page works. Each check names a stable id, the group it came from, and where it can be said, '
            . 'what to do about it. `serving` is the one-word summary: `ok`, or what is being served instead.',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'timeout', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5)),
            new OA\Parameter(name: 'attempts', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 3)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Health report', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'healthy', type: 'boolean', nullable: true),
                        new OA\Property(
                            property: 'serving',
                            type: 'string',
                            description: '`ok` when the application is serving itself; otherwise what it is serving '
                                . 'instead - `placeholder`, `missing_entry`, `directory_listing`, `error_page` - or '
                                . '`unknown` when nothing answered. `restarting` means nothing answered because the '
                                . 'container keeps exiting, which is a different problem from `unknown`: the process '
                                . 'is never up rather than up and not publishing. `unclaimed_install` means `/` redirects to the '
                                . 'application\'s own unfinished installer, which the first visitor can complete; it is a warning '
                                . 'and does not make the deploy partial.'
                        ),
                        new OA\Property(property: 'ports', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'port', type: 'integer'),
                            new OA\Property(property: 'scheme', type: 'string', nullable: true),
                            new OA\Property(property: 'status', type: 'string', enum: ['ok', 'fail']),
                            new OA\Property(property: 'http_code', type: 'integer', nullable: true),
                            new OA\Property(property: 'time', type: 'number', nullable: true),
                            new OA\Property(property: 'detail', type: 'string'),
                        ], type: 'object')),
                        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', description: 'Stable check id, e.g. `entry-served`.'),
                            new OA\Property(property: 'group', type: 'string', description: '`_baseline`, or the runtime the check belongs to.'),
                            new OA\Property(property: 'status', type: 'string', enum: ['pass', 'fail', 'skipped']),
                            new OA\Property(property: 'severity', type: 'string', enum: ['error', 'warning', 'info']),
                            new OA\Property(property: 'title', type: 'string', description: 'What is wrong, in one sentence.'),
                            new OA\Property(property: 'detail', type: 'string', nullable: true, description: 'Why, read from the project, on a failure; on a check the manifest skips, its stated reason.'),
                            new OA\Property(property: 'fix', type: 'string', nullable: true),
                            new OA\Property(property: 'evidence', type: 'object', nullable: true),
                        ], type: 'object')),
                    ], type: 'object'),
                ],
            )),
        ],
    )]
    public function show(string $username, Request $request): JsonResponse
    {
        /**
         * @var array{
         *   timeout?: int,
         *   attempts?: int,
         * }
         */
        $params = $request->validate([
            'timeout' => 'nullable|integer|min:1|max:30',
            'attempts' => 'nullable|integer|min:1|max:10',
        ]);

        $report = $this->getDind($username)->appHealth()->check(
            (int) ($params['timeout'] ?? 5),
            (int) ($params['attempts'] ?? 3),
        );

        return new JsonResponse(['data' => $report]);
    }
}
