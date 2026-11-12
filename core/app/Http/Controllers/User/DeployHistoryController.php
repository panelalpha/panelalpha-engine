<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ReadsDeployLogs;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeployHistoryListRequest;
use App\Lib\Deploy\DeployLog\DeployHistory;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Findings\Hints\FindingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

class DeployHistoryController extends Controller
{
    use ReadsDeployLogs;

    #[OA\Get(
        path: '/projects/{username}/deploys',
        summary: 'List the deploys the project still has a log of, newest first',
        description: 'Container projects only (template dind); any other project keeps no deploy log and answers 403. '
            .'One item per kept deploy log; the project keeps the newest DEPLOY_LOG_KEEP of them (10 by default) and its newest install\'s, however old. '
            .'`status` is the deploy-log status (running, success, partial, failed, cancelled), or `unknown` for an older '
            .'deploy whose log ends without a verdict; a partial deploy lists its `warnings`, each with the `hints` that '
            .'resolve it. `kind` is install for the deploy the project\'s create ran and '
            .'redeploy for every later one. `source` is where it came from (git, hook, archive, rebuild, template), '
            .'`branch` and `commit` what it deployed, when known. `trigger` is who started it (api, mcp, cli, hook) and '
            .'`action` what was asked for, both kept in the deploy log; `task_id` is the newest task that ran it, null for '
            .'a deploy run without one. Times are unix seconds.',
        x: ['mcp-description' => 'The project\'s kept deploys, newest first: id, status, error, warnings, kind (install, redeploy), '
            .'source, branch, commit, trigger (api, mcp, cli, hook), action, task_id and unix start/finish times. Read one '
            .'with deploy_log_read. Container projects only, as for deploy_log_get. Pass page/per_page for one page.'],
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'With per_page or page the answer is one page, newest first, with `meta` and `links` as other lists have, and only that page\'s logs are read. Without either, every kept deploy.',
                schema: new OA\Schema(type: 'integer', default: DeployHistoryListRequest::DEFAULT_PER_PAGE, maximum: DeployHistoryListRequest::MAX_PER_PAGE, minimum: 1)
            ),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Kept deploys', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DeployHistoryItem')),
                    new OA\Property(property: 'meta', type: 'object', description: 'Only when page or per_page was given.'),
                    new OA\Property(property: 'links', type: 'object', description: 'Only when page or per_page was given.'),
                ],
            )),
            new OA\Response(response: 403, description: 'Not a container project: it keeps no deploy log'),
            new OA\Response(response: 404, description: 'Project not found'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(string $username, DeployHistoryListRequest $request): JsonResponse
    {
        $user = $this->deployLogProject($username);
        if (! $request->isPaged()) {
            return new JsonResponse(['data' => array_map(static fn (array $entry): array => self::withHints($entry, $username), DeployHistory::of($user))]);
        }

        $page = DeployHistory::page($user, $request->page(), $request->perPage())
            ->through(static fn (array $entry): array => self::withHints($entry, $username));

        return JsonResource::collection($page)->response();
    }

    #[OA\Get(
        path: '/projects/{username}/deploys/{deploy}/log',
        summary: 'Read a page of one kept deploy\'s log by its id',
        description: 'The same JSON-lines page as GET /projects/{username}/deploy-log, for any deploy GET '
            .'/projects/{username}/deploys lists, with that deploy\'s item beside it. While `more` is true there are '
            .'lines after this page: ask again with `offset` set to `next_offset`.',
        x: ['mcp-description' => 'A page of one kept deploy\'s log from `offset`, by the id deploy_list names. While `more` '
            .'is true, call again with `next_offset`. deploy_log_get follows the latest deploy instead. Container projects only.'],
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'deploy', in: 'path', required: true, description: 'Deploy id from the deploy list', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
            new OA\Parameter(
                name: 'max_bytes',
                in: 'query',
                required: false,
                description: 'Upper bound on the log text in one page, at least 1024. Omitted: up to 2000 lines, whatever their size.',
                schema: new OA\Schema(type: 'integer', minimum: 1024),
                x: ['mcp-default' => '49152', 'mcp-description' => 'Page size in bytes, at least 1024.'],
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deploy log page', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 403, description: 'Not a container project: it keeps no deploy log'),
            new OA\Response(response: 404, description: 'Project or deploy not found'),
        ],
    )]
    public function log(string $username, string $deploy, Request $request): JsonResponse
    {
        $entry = DeployHistory::find($this->deployLogProject($username), $deploy)
            ?? abort(new JsonResponse(['message' => 'Deploy not found'], 404));

        $offset = max(0, (int) $request->query('offset', 0));
        $maxBytes = $request->filled('max_bytes') ? max(1024, (int) $request->query('max_bytes')) : null;

        return new JsonResponse(['data' => self::withHints($entry, $username) + DeployLogger::forDeploy($username, $deploy)->read($offset, maxBytes: $maxBytes)]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function withHints(array $entry, string $username): array
    {
        $entry['warnings'] = FindingPresenter::present($entry['warnings'] ?? [], $username);
        $entry['failure'] = FindingPresenter::presentOne($entry['failure'] ?? null, $username);

        return $entry;
    }
}
