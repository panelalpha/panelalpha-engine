<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Http\Requests\ProjectSourceInspectRequest;
use App\Http\Requests\SourceInspectRequest;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Vault\RequestVault;
use App\Models\User;
use App\OpenApi\ApiDefinitions;
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
    #[OA\Post(
        path: '/source/inspect',
        summary: 'Inspect an application source and report its stack, ports and services',
        description: 'Detects what an application is without deploying it. The source may be a Git '
            . 'repository URL, an absolute path on this server, or the username of an existing '
            . 'project (its files under the account home directory). A public github.com '
            . 'repository is read from its file list and only the files detection reads are '
            . 'fetched; any other Git source, or one that cannot be read that way, is cloned '
            . 'shallow into a temporary directory and removed again. `source.method` says which '
            . '(`tree` or `clone`). Values from .env files are '
            . 'never returned - only the variable names. A project username returns the same '
            . 'report as GET /projects/{username}/inspect, including the `deployment` snapshot '
            . 'its last deploy froze and the `drift` between that and the files on disk - so a '
            . 'caller that already has a username does not need the other endpoint. A token limited '
            . 'to some projects may name only those as a project source.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source'],
            properties: [
                new OA\Property(
                    property: 'source',
                    type: 'string',
                    description: 'Repository URL (the scheme may be left out), or the username of an existing project.',
                    example: 'github.com/owner/repo'
                ),
                new OA\Property(
                    property: 'type',
                    type: 'string',
                    enum: ['git', 'project'],
                    description: 'How to read `source`. Detected from its shape when omitted. `path` '
                        . 'is not accepted: inspecting an arbitrary server directory is not exposed '
                        . 'over the API.'
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
                        . 'A `vault:<id>` of a `global` vault entry is accepted here in place of the '
                        . 'literal token. A `project` entry is accepted while no project owns it yet, and '
                        . 'inspecting does not assign it; one a project owns is refused. Omitted, the clone '
                        . 'is anonymous.'
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
            new OA\Response(response: 403, description: ApiDefinitions::OUT_OF_SCOPE_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
        $type = $this->sourceType($source, $params['type'] ?? null);

        if ($type === SourceResolver::TYPE_PROJECT) {
            return $this->reportProject($source, $params['subdirectory'] ?? null, $plan, $recipe);
        }

        $this->refuseServerDirectory($type);
        $resolved = $this->cloneGitSource($source, $params['branch'] ?? null, $params['subdirectory'] ?? null);

        return $this->reportAndRelease($resolved, $params['subdirectory'] ?? null, $plan, $recipe);
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
        x: ['mcp-description' => "What the project's files are - stack, ports, services, the commands a deploy "
            . 'would run - next to what the last deploy recorded, and where the two differ. .env values '
            . 'are never returned, only their names.'],
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
                schema: new OA\Schema(type: 'string'),
                x: ['mcp-description' => 'Directory under the account home. Default: project.']
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

    /** @throws ProblemException when the source is not recognisable and no `type` was passed */
    private function sourceType(string $source, ?string $type): string
    {
        $type = $type ?? SourceResolver::classify($source);
        if ($type === null) {
            throw ProblemException::one(
                'source',
                'source_unrecognised',
                'Could not tell what this source is. Pass `type` as git, path or project.'
            );
        }

        return $type;
    }

    /** @throws ProblemException for anything but a Git source */
    private function refuseServerDirectory(string $type): void
    {
        if ($type !== SourceResolver::TYPE_GIT) {
            throw ProblemException::one(
                'type',
                'source_type_unsupported',
                'Inspecting an arbitrary server directory is not supported. Use `type: project` '
                    . 'with the project username, or a Git repository URL.'
            );
        }
    }

    /**
     * Clones a Git source. Its git_token may be a global `vault:<id>`, since an inspection belongs
     * to no project; no token clones anonymously.
     */
    private function cloneGitSource(string $source, ?string $branch, ?string $subdirectory): ResolvedSource
    {
        try {
            return $this->resolver()->fromGit(
                $source,
                $branch,
                RequestVault::get('git_token', null),
                $subdirectory
            );
        } catch (InspectException $e) {
            throw ProblemException::of([$e->toProblem()]);
        }
    }

    /** Reports on a clone, which is a real checkout on disk and is released whatever the report does. */
    private function reportAndRelease(ResolvedSource $resolved, ?string $subdirectory, ?DeployPlan $plan, ?string $recipe): JsonResponse
    {
        try {
            return $this->report($resolved, $subdirectory, null, $plan, $recipe);
        } catch (InspectException $e) {
            throw ProblemException::of([$e->toProblem()]);
        } finally {
            $resolved->release();
        }
    }

    /**
     * A hosting account's own files, plus what its last deploy decided. Problems name `project`,
     * not `source`, because this endpoint is addressed by username.
     */
    private function reportProject(
        string $username,
        ?string $relative,
        ?DeployPlan $plan = null,
        ?string $recipe = null
    ): JsonResponse {
        $user = $this->projectOr404($username);

        try {
            $home = $this->projectHome($user);
            $directory = SourceResolver::projectDirectory($home, $user->getMainDomain()?->getDocumentRoot(), $relative);
            $resolved = $this->resolver()->fromDirectory(SourceResolver::TYPE_PROJECT, $username, $directory);

            return $this->report(
                $resolved,
                SourceResolver::relativeTo($home, $resolved->dir),
                $user,
                $plan,
                $recipe
            );
        } catch (InspectException $e) {
            throw ProblemException::of([$e->toProblem('project')]);
        }
    }

    /** getHomeDir() honours a home recorded somewhere other than /home/<username>, and falls back to it. */
    private function projectHome(User $user): string
    {
        return rtrim($user->getHomeDir(), '/');
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
        $dir = $this->inspectedDirectory($resolved, $subdirectory);
        $repositoryUrl = $this->repositoryUrl($resolved) ?? $user?->getGitRepo();
        $this->overlayRecipeFiles($resolved, $dir, $repositoryUrl);

        $report = AppInspector::inspect($dir, $repositoryUrl, $plan, $recipe);

        $data = ['source' => $this->describe($resolved, $subdirectory)];
        if ($user !== null) {
            $data += $this->deploymentAndDrift($user, $resolved, $report);
        }

        return new JsonResponse(['data' => array_merge($data, $report)]);
    }

    /** A project's directory was already chosen and checked against the account home; other sources descend here. */
    private function inspectedDirectory(ResolvedSource $resolved, ?string $subdirectory): string
    {
        return $resolved->type === SourceResolver::TYPE_PROJECT
            ? $resolved->dir
            : SourceResolver::descend($resolved->dir, $subdirectory);
    }

    /**
     * The deploy lays a recipe's files over the checkout before detection, so a clone (ours to
     * write to) gets them too; otherwise inspect would judge a tree the deploy never sees.
     */
    private function overlayRecipeFiles(ResolvedSource $resolved, string $dir, ?string $repositoryUrl): void
    {
        if ($resolved->type === SourceResolver::TYPE_GIT) {
            RecipeFileOverlay::apply($dir, $repositoryUrl);
        }
    }

    /**
     * What is running in the project, and then where its files have moved on from it.
     *
     * @param array<string, mixed> $report
     * @return array{deployment: mixed, drift: mixed}
     */
    private function deploymentAndDrift(User $user, ResolvedSource $resolved, array $report): array
    {
        $details = $user->getDetails();
        $commit = $resolved->meta['commit'] ?? null;

        return [
            'deployment' => DeploymentSnapshot::describe($details),
            'drift' => DeploymentSnapshot::drift(
                $details,
                $report,
                is_string($commit) ? $commit : null
            ),
        ];
    }

    private function resolver(): SourceResolver
    {
        return new SourceResolver(storage_path('app/source-inspect'), self::cloneTimeout(), new GitHubTree());
    }

    /** As long as a deploy's clone may take (DEPLOY_CLONE_TIMEOUT), so inspect accepts what a deploy would. */
    private static function cloneTimeout(): int
    {
        $seconds = (int) config('deploy.clone_timeout', 600);

        return $seconds > 0 ? $seconds : 600;
    }

    /**
     * The repository this source came from, when anything knows: the URL that
     * was cloned, or the origin remote of a checkout already on disk. It is
     * what decides whether the engine's own app config for that repository
     * applies; a project whose checkout lost its remote falls back to the one it was created from.
     */
    private function repositoryUrl(ResolvedSource $resolved): ?string
    {
        $repository = $resolved->meta['repository'] ?? null;

        return is_string($repository) && $repository !== '' ? $repository : null;
    }

    /**
     * A clone has no `path`: it stops existing before the response is written. `method` is
     * `tree` or `clone` for a git source, null for a directory that was simply read.
     *
     * @return array<string, mixed>
     */
    private function describe(ResolvedSource $resolved, ?string $subdirectory): array
    {
        $subdirectory = trim((string) $subdirectory, '/ ');

        return [
            'type' => $resolved->type,
            'reference' => $resolved->reference,
            'path' => $resolved->type === SourceResolver::TYPE_GIT ? null : $resolved->dir,
            'subdirectory' => $subdirectory === '' ? null : $subdirectory,
            'git' => [
                'repository' => $resolved->meta['repository'] ?? null,
                'branch' => $resolved->meta['branch'] ?? null,
                'commit' => $resolved->meta['commit'] ?? null,
            ],
            'method' => $resolved->meta['method'] ?? null,
        ];
    }
}
