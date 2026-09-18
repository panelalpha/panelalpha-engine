<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Http\Requests\ProjectSourceInspectRequest;
use App\Http\Requests\SourceInspectRequest;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Vault\RequestVault;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Read an application without deploying it.
 *
 * Four kinds of source answer the same question — what is this, what does it
 * listen on, what does it need — and they are the four a caller actually has:
 * a repository URL, a directory on this server, a hosting project already
 * holding files, and a bare `github.com/owner/repo` somebody pasted.
 *
 * The answer comes from the deploy pipeline's own detection, so it is a
 * prediction of the deploy rather than a second opinion about it. For a
 * project that has already deployed it is also a comparison: the account
 * carries a frozen snapshot of what the last deploy decided, and the interesting
 * part is where that no longer matches the files on disk.
 */
class SourceInspectionController extends Controller
{
    /** Seconds a clone may take before the inspection gives up. */
    private const CLONE_TIMEOUT = 180;

    #[OA\Post(
        path: '/source/inspect',
        summary: 'Inspect an application source and report its stack, ports and services',
        description: 'Detects what an application is without deploying it. The source may be a Git '
            . 'repository URL, an absolute path on this server, or the username of an existing '
            . 'project (its files under the account home directory). Git sources are cloned '
            . 'shallow into a temporary directory and removed again. Values from .env files are '
            . 'never returned - only the variable names. A project username returns the same '
            . 'report as GET /projects/{username}/inspect, including the `deployment` snapshot '
            . 'its last deploy froze and the `drift` between that and the files on disk - so a '
            . 'caller that already has a username does not need the other endpoint.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source'],
            properties: [
                new OA\Property(
                    property: 'source',
                    type: 'string',
                    description: 'Repository URL (the scheme may be left out), absolute path, or project username.',
                    example: 'github.com/owner/repo'
                ),
                new OA\Property(
                    property: 'type',
                    type: 'string',
                    enum: ['git', 'path', 'project'],
                    description: 'How to read `source`. Detected from its shape when omitted.'
                ),
                new OA\Property(property: 'branch', type: 'string', description: 'Git branch to inspect.'),
                new OA\Property(
                    property: 'subdirectory',
                    type: 'string',
                    description: 'Inspect this directory inside the source instead of its root. For a '
                        . 'project the source root is the account home directory, so this is a path '
                        . 'under /home/<username> such as "project/apps/api" or "public_html".'
                ),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    description: 'Access token for a private HTTPS repository. '
                        . 'A `vault:<ref>` from vault_secret_create is accepted here in place of the '
                        . 'literal token -- the secret it stands for is substituted at read time.'
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    description: 'Preview the deploy this recipe would produce instead of the one '
                        . 'detection picks. Takes an id from `application.candidates` in a previous '
                        . 'inspection, and is the same field the deploy endpoints accept - so what '
                        . 'this previews is what deploying with it executes. An id this engine does '
                        . 'not ship comes back as `application.issue` rather than an error.',
                    example: 'php'
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    description: 'Preview a deploy that overrides stages: the same `stages` payload the '
                        . 'deploy endpoints take, reported here without deploying anything. A stage named '
                        . 'replaces that stage in the returned plan; one left out keeps the platform '
                        . "defaults. Each command in `application.stages` says where it came from in "
                        . '`source`: manifest, app_config or request.'
                ),
            ],
        )),
        tags: ['Deploy'],
        responses: [
            new OA\Response(response: 200, description: 'Inspection result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Source could not be read', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function inspect(SourceInspectRequest $request): JsonResponse
    {
        /**
         * @var array{
         *   source: string,
         *   type?: ?string,
         *   branch?: ?string,
         *   subdirectory?: ?string,
         *   git_token?: ?string,
         *   stages?: ?array<string, mixed>,
         *   recipe?: ?string,
         * }
         */
        $params = $request->validated();
        $source = $params['source'];
        $plan = DeployPlanInput::parse($params['stages'] ?? null);
        $recipe = RecipeChoiceInput::parse($params['recipe'] ?? null);
        $type = $params['type'] ?? SourceResolver::classify($source);
        if ($type === null) {
            throw ProblemException::one(
                'source',
                'source_unrecognised',
                'Could not tell what this source is. Pass `type` as git, path or project.'
            );
        }

        if ($type === SourceResolver::TYPE_PROJECT) {
            return $this->reportProject($source, $params['subdirectory'] ?? null, $plan, $recipe);
        }

        try {
            // git_token may be a `vault:<ref>` -- resolved here, so the
            // transient clone uses the pasted secret and nothing downstream
            // (or in the log) ever sees it. No token at all falls back to the
            // engine's own: this clone is thrown away, so nothing is copied or
            // frozen by inheriting it, and inspecting a private repository
            // stops needing a token the caller has already given the engine
            // once.
            $resolved = $type === SourceResolver::TYPE_GIT
                ? $this->resolver()->fromGit($source, $params['branch'] ?? null, RequestVault::getOrGlobal('git_token'))
                : $this->resolver()->fromDirectory(SourceResolver::TYPE_PATH, $source, $source);
        } catch (InspectException $e) {
            throw ProblemException::of([$e->toProblem()]);
        }

        try {
            return $this->report($resolved, $params['subdirectory'] ?? null, null, $plan, $recipe);
        } catch (InspectException $e) {
            throw ProblemException::of([$e->toProblem()]);
        } finally {
            // A git source is a real clone on disk. Nothing below this line
            // gets to decide whether to keep it.
            $resolved->release();
        }
    }

    #[OA\Get(
        path: '/projects/{username}/inspect',
        summary: "Inspect a project's deployed application files",
        description: "Reports what the project's files are - stack, ports, backing services, the "
            . 'commands a deploy would run - alongside the snapshot the last deploy froze onto the '
            . 'account, and lists where the two disagree. Reads /home/<username>/project by default '
            . "(the main domain's document root for the classic PHP templates); `subdirectory` "
            . 'selects another directory under the account home. Values from .env files are never '
            . 'returned - only the variable names. This is POST /source/inspect with `type: '
            . 'project`, addressed by username: same report, same field names, and readable with a '
            . 'GET. The older spellings - the `/source-inspection` path and the `path` parameter - '
            . 'still work and are deprecated.',
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'subdirectory',
                in: 'query',
                required: false,
                description: 'Directory under the account home to inspect, e.g. "project", '
                    . '"public_html" or "project/apps/api". Absolute paths are accepted when they '
                    . 'are inside the home directory. Named as it is on POST /source/inspect; '
                    . '`path` is accepted as a deprecated alias.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inspection result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Directory could not be read', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function project(string $username, ProjectSourceInspectRequest $request): JsonResponse
    {
        /** @var array{subdirectory?: ?string} $params */
        $params = $request->validated();

        return $this->reportProject($username, $params['subdirectory'] ?? null);
    }

    /**
     * A hosting account's own files, plus what its last deploy decided.
     */
    private function reportProject(
        string $username,
        ?string $relative,
        ?DeployPlan $plan = null,
        ?string $recipe = null
    ): JsonResponse {
        $user = User::findByUsername($username);
        if (!$user) {
            return new JsonResponse(['message' => 'Project not found'], 404);
        }

        try {
            // getHomeDir() honours an account whose home was recorded
            // somewhere other than /home/<username>, and falls back to it.
            $home = rtrim($user->getHomeDir(), '/');
            $directory = $this->projectDirectory($user, $home, $relative);
            $resolved = $this->resolver()->fromDirectory(SourceResolver::TYPE_PROJECT, $username, $directory);

            return $this->report(
                $resolved,
                SourceResolver::relativeTo($home, $resolved->dir),
                $user,
                $plan,
                $recipe
            );
        } catch (InspectException $e) {
            // `project`, not `source`: this endpoint is addressed by username.
            throw ProblemException::of([$e->toProblem('project')]);
        }
    }

    /**
     * Inspect what a resolved source points at and shape the response.
     *
     * @throws InspectException
     */
    private function report(
        ResolvedSource $resolved,
        ?string $subdirectory,
        ?User $user = null,
        ?DeployPlan $plan = null,
        ?string $recipe = null
    ): JsonResponse {
        // A project's directory was already chosen and checked against the
        // account home; for the other two the subdirectory is applied here.
        $dir = $resolved->type === SourceResolver::TYPE_PROJECT
            ? $resolved->dir
            : SourceResolver::descend($resolved->dir, $subdirectory);

        // An account remembers the repository it was created from even when
        // the checkout on disk has lost its remote, and that URL is what
        // decides whether the engine's own app config for that repository applies.
        $repositoryUrl = $this->repositoryUrl($resolved) ?? $user?->getGitRepo();

        $report = AppInspector::inspect($dir, $repositoryUrl, $plan, $recipe);

        $data = ['source' => $this->describe($resolved, $subdirectory)];

        if ($user !== null) {
            $details = $user->getDetails();
            $commit = $resolved->meta['commit'] ?? null;
            // What is running, and then where the files have moved on from it.
            $data['deployment'] = DeploymentSnapshot::describe($details);
            $data['drift'] = DeploymentSnapshot::drift(
                $details,
                $report,
                is_string($commit) ? $commit : null
            );
        }

        return new JsonResponse(['data' => array_merge($data, $report)]);
    }

    private function resolver(): SourceResolver
    {
        return new SourceResolver(storage_path('app/source-inspect'), self::CLONE_TIMEOUT);
    }

    /**
     * Where a hosting project keeps the application's files.
     *
     * `~/project` for a container project, the main domain's document root for
     * the classic PHP templates. Both are checked because the endpoint takes a
     * username, not a template. `$relative` overrides the guess, and may not
     * leave the home directory.
     *
     * @throws InspectException
     */
    private function projectDirectory(User $user, string $home, ?string $relative): string
    {
        $relative = trim((string) $relative);
        if ($relative !== '') {
            return SourceResolver::descend($home, SourceResolver::underRoot($home, $relative));
        }

        $appDir = $home . '/project';
        if (is_dir($appDir)) {
            return $appDir;
        }

        $domain = $user->getMainDomain();
        if ($domain !== null) {
            $documentRoot = $home . $domain->getDocumentRoot();
            if (is_dir($documentRoot)) {
                return $documentRoot;
            }
        }

        return $appDir;
    }

    /**
     * The repository this source came from, when anything knows: the URL that
     * was cloned, or the origin remote of a checkout already on disk. It is
     * what decides whether the engine's own app config for that repository
     * applies.
     */
    private function repositoryUrl(ResolvedSource $resolved): ?string
    {
        $repository = $resolved->meta['repository'] ?? null;

        return is_string($repository) && $repository !== '' ? $repository : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ResolvedSource $resolved, ?string $subdirectory): array
    {
        $subdirectory = trim((string) $subdirectory, '/ ');

        return [
            'type' => $resolved->type,
            'reference' => $resolved->reference,
            // A temp clone's path is an implementation detail that stops
            // existing before the response is written.
            'path' => $resolved->type === SourceResolver::TYPE_GIT ? null : $resolved->dir,
            'subdirectory' => $subdirectory === '' ? null : $subdirectory,
            'git' => [
                'repository' => $resolved->meta['repository'] ?? null,
                'branch' => $resolved->meta['branch'] ?? null,
                'commit' => $resolved->meta['commit'] ?? null,
            ],
        ];
    }
}
