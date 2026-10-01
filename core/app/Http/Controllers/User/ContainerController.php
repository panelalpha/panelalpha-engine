<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\System\Project\Dind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ContainerController extends Controller
{
    private function getDind(string $username): Dind
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'Container management is only available for dind users'], 403));
        }

        $runtime = $user->project()->runtime();
        if (!$runtime instanceof Dind) {
            abort(new JsonResponse(['message' => 'Container management is only available for dind users'], 403));
        }

        return $runtime;
    }

    #[OA\Get(
        path: '/projects/{username}/containers',
        summary: 'List Docker containers of a project',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of containers', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Container'))],
            )),
        ],
    )]
    public function index(string $username): JsonResponse
    {
        $dind = $this->getDind($username);
        return new JsonResponse(['data' => $dind->getContainers()]);
    }

    #[OA\Post(
        path: '/projects/{username}/containers/action',
        summary: 'Perform a project-level Docker Compose action',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['action'],
            properties: [new OA\Property(property: 'action', type: 'string', enum: ['up', 'stop', 'restart', 'down', 'pull'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Action result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer'),
                    new OA\Property(property: 'stdout', type: 'string'),
                    new OA\Property(property: 'stderr', type: 'string'),
                ],
            )),
        ],
    )]
    public function projectAction(string $username, Request $request): JsonResponse
    {
        /**
         * @var array{
         *   action: string
         * } $validated
         */
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['up', 'stop', 'restart', 'down', 'pull'])],
        ]);

        $dind = $this->getDind($username);
        $result = $dind->projectAction($validated['action']);

        return new JsonResponse($result, $result['exit_code'] === 0 ? 200 : 500);
    }

    #[OA\Post(
        path: '/projects/{username}/containers/{service}/action',
        summary: 'Perform a service-level Docker action',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'web')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['action'],
            properties: [new OA\Property(property: 'action', type: 'string', enum: ['start', 'stop', 'restart'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Action result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer'),
                    new OA\Property(property: 'stdout', type: 'string'),
                ],
            )),
        ],
    )]
    public function serviceAction(string $username, string $service, Request $request): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return new JsonResponse(['message' => 'Invalid service name'], 422);
        }

        /**
         * @var array{
         *   action: string
         * } $validated
         */
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['start', 'stop', 'restart'])],
        ]);

        $dind = $this->getDind($username);
        $result = $dind->serviceAction($service, $validated['action']);

        return new JsonResponse($result, $result['exit_code'] === 0 ? 200 : 500);
    }

    #[OA\Get(
        path: '/projects/{username}/containers/{service}/logs',
        summary: 'Get logs from a Docker service',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 500)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service logs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'string')],
            )),
        ],
    )]
    public function logs(string $username, string $service, Request $request): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return new JsonResponse(['message' => 'Invalid service name'], 422);
        }

        $lines = min((int) $request->input('lines', 200), 500);

        $dind = $this->getDind($username);
        $logs = $dind->getServiceLogs($service, $lines);

        return new JsonResponse(['data' => $logs]);
    }
}
