<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectSourceSetRequest;
use App\Http\Responses\AcceptedTask;
use App\Lib\Project\Create\ProjectSourceSetter;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * PUT /projects/{username}/source: give a project that exists the source it
 * deploys from, in the shape POST /projects takes, and deploy it.
 */
class ProjectSourceController extends Controller
{
    #[OA\Put(
        path: '/projects/{username}/source',
        summary: 'Set the source a container project deploys from, and deploy it',
        description: 'Takes the `source` object POST /projects takes, for a container project that exists, and '
            .'`build` beside it. **git**: for a project created without a source that has not run an '
            .'application yet (409 `source_already_attached` when it already deploys from git, '
            .'`project_not_eligible` when it is php_hosting or already runs an application); `url` may be an SSH '
            .'remote with `auth: {"type": "deploy_key", "key": "vault:<key>"}`. The repository is checked before '
            .'anything is stored, as on create. **archive**: `upload_id` (POST /uploads), `url` (downloaded by the '
            .'deploy) or `path` (a file already in the project home, e.g. /project/app.zip). It replaces the files '
            .'in ~/project and deploys them; refused with 409 on a php_hosting project and on one that deploys from '
            .'git. `build.env_vars` are merged onto the project\'s own (null clears them); `build.recipe` and '
            .'`build.stages` apply to this deploy only. Answers 202 with the deploy task, followed like a create '
            .'(`details.action` `attach_source` or `deploy_archive`); 409 with `task_id` while another deploy of '
            .'the project is queued or running. Refusals name the field as sent (`source.url`, `build.env_vars`).',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source'],
            properties: [
                new OA\Property(property: 'source', ref: '#/components/schemas/ProjectSource'),
                new OA\Property(property: 'build', ref: '#/components/schemas/ProjectBuild'),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Source stored and its deploy queued: the task, with `data.inspection` for a git source', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'The project cannot take this source, or a deploy of it is queued or running', content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error, with `problems[].field` as sent', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The project could not be locked to start the deploy'),
        ],
        x: ['mcp-description' => 'Sets the git or archive source of an existing container project and deploys it. '
            .'Same source object as project_create: git {url, branch, auth} for a project created without a source, '
            .'or archive {upload_id | url | path}, which replaces ~/project. Answers a task id; follow it with task_get.'],
    )]
    public function update(string $username, ProjectSourceSetRequest $request, ProjectSourceSetter $setter): JsonResponse
    {
        return AcceptedTask::of($setter->set($this->projectOr404($username), $request->sourceInput()));
    }
}
