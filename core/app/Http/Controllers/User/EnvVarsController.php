<?php

namespace App\Http\Controllers\User;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnvVarsUpdateRequest;
use App\Http\Resources\EnvVarsResource;
use App\Http\Resources\TaskResource;
use App\Jobs\ApplyEnvVars;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use App\Models\User;
use App\OpenApi\ApiDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EnvVarsController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/env-vars',
        summary: 'List the environment variables the engine holds for a project',
        description: 'Container projects only (`template: dind`, or a git project); a PHP hosting project answers 422. '
            . 'Lists the stored KEY/value pairs with every value masked, and the keys the last deploy applied. '
            . '`reveal=1` returns the values instead. What the application actually sees also carries generated '
            . 'secrets and the recipe\'s own defaults; this is what the account set.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'reveal',
                in: 'query',
                required: false,
                description: 'Set to 1 to return the values themselves instead of masking them.',
                schema: new OA\Schema(type: 'string', enum: ['0', '1'], default: '0'),
                x: ['mcp-hide' => true]
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The stored variables and the keys the last deploy applied', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EnvVars')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The project is not a container project', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(string $username, Request $request): EnvVarsResource
    {
        $user = $this->projectOr404($username);
        $this->assertDind($user);

        return new EnvVarsResource([
            'vars' => self::entries($user->getEnvVars(), $request->boolean('reveal')),
            'applied_keys' => self::appliedKeys($user),
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/env-vars',
        summary: 'Set the environment variables the engine holds for a project',
        description: 'Container projects only (`template: dind`, or a git project); a PHP hosting project answers 422. '
            . 'Stores KEY/value pairs for the application. An empty value removes that key: it is unset from the '
            . 'running application at once, and a default the recipe sets for the key returns at the next deploy. '
            . '`replace: true` replaces the stored map instead of merging onto it, so an empty `env_vars` with '
            . '`replace: true` clears every key, and a `vault:<id>` value is read as the secret it names. '
            . '`apply: none` stores them for the next deploy and answers 200 with the stored keys; '
            . '`apply: recreate` answers 202 with a task and recreates the changed services in place, with no image '
            . 'rebuild. A value read at build time (Vite, Next.js) still needs a rebuild. While a deploy or env var '
            . 'apply of the project is queued or running the answer is 409 with its `task_id`.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['env_vars'],
            properties: [
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true),
                    example: ['APP_ENV' => 'production', 'OLD_KEY' => ''],
                    description: 'KEY => value. An empty value removes that key; an empty object with `replace: true` clears every key.'
                ),
                new OA\Property(
                    property: 'replace',
                    type: 'boolean',
                    default: false,
                    description: 'True replaces the stored map with this one instead of merging onto it.'
                ),
                new OA\Property(
                    property: 'apply',
                    type: 'string',
                    enum: ['none', 'recreate'],
                    default: 'recreate',
                    description: '`none` stores the values for the next deploy; `recreate` applies them now by '
                        . 'recreating the services whose configuration changed, with no image rebuild.'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: '`apply: none`; `data.keys` names what is stored', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'keys', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                ],
            )),
            new OA\Response(response: 202, description: 'Queued to apply; `data` is the task to follow', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'A deploy or env var apply of this project is already queued or running. ' . ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error, or the project is not a container project', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(string $username, EnvVarsUpdateRequest $request, ProjectRebuild $rebuild): JsonResponse
    {
        $user = $this->projectOr404($username);
        $this->assertDind($user);

        /** @var array{env_vars: array<string, string|null>, replace?: bool, apply?: string} $params */
        $params = $request->validated();
        $current = $user->getEnvVars();
        $vars = self::merged($params, $current);
        $apply = $params['apply'] ?? 'recreate';

        return $rebuild->whileIdle($user, function () use ($user, $vars, $apply, $current): JsonResponse {
            self::store($user, $vars);

            return $apply === 'none'
                ? new JsonResponse(['data' => ['keys' => array_keys($vars)]])
                : $this->queueApply($user, $current);
        });
    }

    private function assertDind(User $user): void
    {
        if ($user->getTemplate() === 'dind' || $user->hasGitProject()) {
            return;
        }

        throw ProblemException::one(
            'env_vars',
            'env_vars_dind_only',
            'Environment variables are managed for container projects only. A PHP hosting project keeps its '
            . 'configuration in its own files.'
        );
    }

    /**
     * The map to store: the incoming values merged onto the stored ones, or
     * replacing them. A value of `""`/null removes its key either way.
     *
     * @param array{env_vars: array<string, string|null>, replace?: bool} $params
     * @param array<string, string> $current
     * @return array<string, string>
     */
    private static function merged(array $params, array $current): array
    {
        return EnvVarOverrides::applyIncoming(
            $params['env_vars'],
            ($params['replace'] ?? false) ? [] : $current
        );
    }

    /**
     * @param array<string, string> $vars
     */
    private static function store(User $user, array $vars): void
    {
        $user->setDetails(['env_vars' => $vars]);
        $user->save();
    }

    private function queueApply(User $user, array $current): JsonResponse
    {
        $task = Task::start(
            jobType: ApplyEnvVars::class,
            queue: 'default',
            username: $user->username,
            details: [
                'action' => 'env_apply',
                'keys' => array_keys($user->getEnvVars()),
            ],
        );
        ApplyEnvVars::dispatch($user->username, array_keys($current), (int) $user->getKey())->attachTask($task);

        return TaskResource::make($task)->response()->setStatusCode(202);
    }

    /**
     * @param array<string, string> $vars
     * @return list<array{key: string, value: ?string, masked: bool}>
     */
    private static function entries(array $vars, bool $reveal): array
    {
        $entries = [];
        foreach ($vars as $key => $value) {
            $entries[] = [
                'key' => $key,
                'value' => $reveal ? $value : null,
                'masked' => !$reveal,
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function appliedKeys(User $user): array
    {
        $keys = $user->getDetails()['env_override_keys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }
}
