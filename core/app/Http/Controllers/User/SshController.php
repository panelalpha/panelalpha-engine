<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\SshCommandRunRequest;
use App\Lib\Project\ProjectShell;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Shell access to a project's container, the way WP-CLI has its own endpoint:
 * one command per request, run and reported, no session held open.
 */
class SshController extends Controller
{
    #[OA\Post(
        path: '/projects/{username}/ssh/command',
        summary: 'Run a shell command inside the project container',
        description: 'Runs one command as the project user inside its container, the way an SSH session would — '
            . 'the command line reaches bash intact, so pipes, redirection and globs work. '
            . 'A non-zero exit is reported in the response, not as an HTTP error. Dind projects only.',
        security: [['bearerAuth' => []]],
        tags: ['SSH'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['command'],
            properties: [
                new OA\Property(property: 'command', type: 'string', example: 'ls -la && cat composer.json'),
                new OA\Property(property: 'cwd', type: 'string', nullable: true, description: 'Directory to run in; defaults to the account home directory.', example: '/home/demo/project'),
                new OA\Property(property: 'timeout', type: 'integer', nullable: true, description: 'Seconds before the command is killed (1-900, default 300).', example: 300),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Command output', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer', example: 0),
                    new OA\Property(property: 'stdout', type: 'string'),
                    new OA\Property(property: 'stderr', type: 'string'),
                ],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Not a dind project, or invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function run(string $username, SshCommandRunRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);
        $params = $request->validated();

        return new JsonResponse(ProjectShell::run(
            $user,
            $params['command'],
            $params['cwd'] ?? null,
            (int)($params['timeout'] ?? SshCommandRunRequest::DEFAULT_TIMEOUT)
        ));
    }
}
