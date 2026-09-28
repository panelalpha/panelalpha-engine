<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\WpCliCommandRunRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class WpCliController extends Controller
{
    #[OA\Post(
        path: '/projects/{username}/wp-cli/command',
        summary: 'Run a WP-CLI command',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['args'],
            properties: [
                new OA\Property(property: 'args', type: 'array', items: new OA\Items(type: 'string'), example: ['plugin', 'list', '--format=json']),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'WP-CLI output', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer', example: 0),
                    new OA\Property(property: 'stdout', type: 'string'),
                    new OA\Property(property: 'stderr', type: 'string'),
                ],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param WpCliCommandRunRequest $request
     * @return JsonResponse
     */
    public function run($username, WpCliCommandRunRequest $request)
    {
        $user = $this->projectOrNotFound($username);

        $args = [];
        foreach ($request->validated()['args'] as $arg) {
            if (!is_string($arg)) {
                throw ValidationException::withMessages([
                    'args' => 'Invalid value',
                ]);
            }
            $args[] = $arg;
        }

        try {
            $result = $user->project()->runWpCli($args);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'args' => $e->getMessage(),
            ]);
        }

        // If this was a WP-CLI cache flush, schedule OLS reload on host
        try {
            $cachePos = null;
            foreach ($args as $i => $a) {
                if ($a === 'cache') {
                    $cachePos = $i;
                    break;
                }
            }
            if ($cachePos !== null && isset($args[$cachePos + 1]) && $args[$cachePos + 1] === 'flush') {
                $system = new \App\System();
                if (
                    ($result['exit_code'] ?? 1) === 0
                    && $system->webserver()->getCurrentWebserver() === 'openlitespeed'
                ) {
                    $system->webserver()->scheduleWebserverReloadInBackground();
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to schedule webserver reload after wp cache flush', ['username' => $username, 'args' => $args, 'error' => $e->getMessage()]);
        }

        return new JsonResponse($result);
    }
}
