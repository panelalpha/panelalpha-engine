<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\GitChangeBranchRequest;
use App\Http\Requests\Git\GitCommitsRequest;
use App\Http\Requests\Git\GitConnectRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Http\Requests\Git\GitPullRequest;
use App\Http\Requests\Git\GitRevertRequest;
use App\Http\Requests\Git\GitStatusRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Lib\DeployHook\DeployHooks;
use App\System\Project\Git;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Git\Exception as GitException;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class GitController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/git/status',
        description: 'Call this first. Optional `path` defaults to `project` on DinD and `public_html` '
            . 'on FPM/LiteSpeed. Query `fetch` updates remote-tracking refs before reporting. The '
            . 'payload includes `managed_by`: `deploy` (account provisioned with git_repo; mutating '
            . 'Git must go through `project_rebuild`) or `site_git` (these Git tools).',
        summary: 'Git repository status',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'fetch', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Git status', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'path', type: 'string'),
                        new OA\Property(property: 'path_key', type: 'string'),
                        new OA\Property(property: 'managed_by', type: 'string', enum: ['deploy', 'site_git']),
                        new OA\Property(property: 'connected', type: 'boolean'),
                        new OA\Property(property: 'repository_exists', type: 'boolean'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function status(string $username, GitStatusRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit($username, $params['path'] ?? null, fn (Git $git) => $git->status($request->boolean('fetch')));
    }

    #[OA\Get(
        path: '/projects/{username}/git/branches',
        description: 'List git branches. Optional `path` defaults to `project` on DinD and `public_html` '
            . 'on FPM/LiteSpeed.',
        summary: 'List git branches',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch list', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function branches(string $username, GitPathRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit($username, $params['path'] ?? null, fn (Git $git) => $git->branches());
    }

    #[OA\Get(
        path: '/projects/{username}/git/commits',
        description: 'List git commits. Optional `path` defaults to `project` on DinD and `public_html` '
            . 'on FPM/LiteSpeed. Query `branch` filters the log; `limit` caps how many commits are returned.',
        summary: 'List git commits',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Commit list', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function commits(string $username, GitCommitsRequest $request): JsonResponse
    {
        $params = $request->validated();
        $limit = isset($params['limit']) ? (int) $params['limit'] : null;
        $branch = isset($params['branch']) && is_string($params['branch']) && $params['branch'] !== ''
            ? $params['branch']
            : null;

        return $this->runGit($username, $params['path'] ?? null, fn (Git $git) => $git->commits($limit, $branch));
    }

    #[OA\Post(
        path: '/projects/{username}/git/connect',
        description: 'Connect a directory to a git remote. Optional `path` defaults to `project` on DinD '
            . 'and `public_html` on FPM/LiteSpeed. Body `repo_url` and `branch` are required. Optional '
            . '`token` is a PAT (never logged). Set `repair` to re-adopt a missing .git. On a `deploy` '
            . 'account without repair this only keeps origin in sync and persists metadata — it does '
            . 'not clone from scratch.',
        summary: 'Connect a directory to a git remote',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repo_url', 'branch'],
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'repo_url', type: 'string', format: 'uri'),
                new OA\Property(property: 'branch', type: 'string'),
                new OA\Property(property: 'token', type: 'string', nullable: true),
                new OA\Property(property: 'auth_type', type: 'string', enum: ['pat']),
                new OA\Property(property: 'repair', type: 'boolean'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Connected', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function connect(string $username, GitConnectRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit($username, $params['path'] ?? null, fn (Git $git) => call_user_func(
            [$git, 'connect'],
            $params['repo_url'] ?? '',
            $params['branch'] ?? '',
            $params['token'] ?? null,
            (bool) ($params['repair'] ?? false),
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/git/disconnect',
        description: 'Disconnect git from a directory. Optional `path` defaults to `project` on DinD and '
            . '`public_html` on FPM/LiteSpeed. Removes `origin` and site-git metadata; does not delete '
            . 'working-tree files. Also removes the checkout\'s Deploy Hook, if it has one -- a '
            . 'disconnected checkout has nothing for a push to deploy. Returns 422 when managed_by is `deploy`.',
        summary: 'Disconnect git from a directory',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string')],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Disconnected', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function disconnect(string $username, GitPathRequest $request, DeployHooks $hooks): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit(
            $username,
            $params['path'] ?? null,
            fn (Git $git, User $user) => $hooks->disconnectAndForget($user, $git),
        );
    }

    #[OA\Put(
        path: '/projects/{username}/git/change-branch',
        description: 'Change the tracked git branch. Optional `path` defaults to `project` on DinD and '
            . '`public_html` on FPM/LiteSpeed. Body `branch` is required. Returns 422 if the working '
            . 'tree is dirty, the remote branch does not exist, or managed_by is `deploy` — then use '
            . '`project_rebuild`.',
        summary: 'Change the tracked git branch',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['branch'],
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'branch', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Branch changed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function changeBranch(string $username, GitChangeBranchRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit(
            $username,
            $params['path'] ?? null,
            fn (Git $git) => $git->changeBranch($params['branch']),
            true,
        );
    }

    #[OA\Put(
        path: '/projects/{username}/git/update-credentials',
        description: 'Update git credentials for a directory. Optional `path` defaults to `project` on '
            . 'DinD and `public_html` on FPM/LiteSpeed. Sending `token` (including empty) updates stored '
            . 'credentials. Omitting `token` is a no-op that returns status.',
        summary: 'Update git credentials for a directory',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'token', type: 'string', nullable: true),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Credentials updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateCredentials(string $username, GitUpdateCredentialsRequest $request): JsonResponse
    {
        $params = $request->validated();
        $provided = array_key_exists('token', $params);

        return $this->runGit(
            $username,
            $params['path'] ?? null,
            fn (Git $git) => $git->updateCredentials($provided ? ($params['token'] ?? null) : null, $provided),
        );
    }

    #[OA\Post(
        path: '/projects/{username}/git/pull',
        description: 'Pull from the git remote. Optional `path` defaults to `project` on DinD and '
            . '`public_html` on FPM/LiteSpeed. Body `strategy` is `ff` (default), `force` '
            . '(`reset --hard` origin/<branch> plus clean -fd), or `push_first`. `ff` fetches and fast-forwards, '
            . 'and git alone decides whether the checkout allows it: untracked files (uploads, caches) and edits to '
            . 'files the incoming commits leave alone do not block it. It returns 422 naming the paths when a local '
            . 'edit or untracked file sits where an incoming commit writes, or 422 when history has diverged '
            . '(for example after a force-push); the checkout is left as it was. On a Deploy-managed checkout '
            . '(`managed_by` is `deploy`) a successful pull rebuilds the app from the pulled files. '
            . 'Confirm with the operator before `force`.',
        summary: 'Pull from the git remote',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'strategy', type: 'string', enum: ['ff', 'force', 'push_first']),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Pulled', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function pull(string $username, GitPullRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit(
            $username,
            $params['path'] ?? null,
            fn (Git $git) => $git->pull($params['strategy'] ?? null),
            true,
        );
    }

    #[OA\Post(
        path: '/projects/{username}/git/push',
        description: 'Push local git changes. Optional `path` defaults to `project` on DinD and '
            . '`public_html` on FPM/LiteSpeed. If the working tree is dirty this tool will commit all '
            . 'changes itself, then push. Returns 422 `Pull first.` when behind the remote, or when '
            . 'managed_by is `deploy`.',
        summary: 'Push local git changes',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string')],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Pushed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function push(string $username, GitPathRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit($username, $params['path'] ?? null, fn (Git $git) => $git->push());
    }

    #[OA\Post(
        path: '/projects/{username}/git/revert',
        description: 'Revert local git changes. Optional `path` defaults to `project` on DinD and '
            . '`public_html` on FPM/LiteSpeed. Runs `reset --hard` and `clean -fd` to `ref` (default '
            . 'HEAD). Discards local changes. Confirm with the operator.',
        summary: 'Revert local git changes',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'ref', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Reverted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function revert(string $username, GitRevertRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->runGit(
            $username,
            $params['path'] ?? null,
            fn (Git $git) => $git->revert($params['ref'] ?? null),
            true,
        );
    }

    private function resolveUser(string $username): User
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse([
                'message' => 'User not found',
            ], 404));
        }

        return $user;
    }

    /**
     * @param callable(Git, User): mixed $action
     */
    private function runGit(string $username, ?string $path, callable $action, bool $redeployIfManaged = false): JsonResponse
    {
        $user = $this->resolveUser($username);

        try {
            $project = $user->project();
            $git = $project->git($path);
            $data = $action($git, $user);
            if ($redeployIfManaged) {
                app(CheckoutRedeploy::class)->afterMutation($git, $project);
            }

            return new JsonResponse(['data' => $data]);
        } catch (GitException $e) {
            if ($e->httpStatus === 422) {
                throw ValidationException::withMessages(['git' => $e->getMessage()]);
            }

            return new JsonResponse([
                'message' => GitUrl::sanitize($e->getMessage()),
            ], $e->httpStatus);
        }
    }
}
