<?php

namespace App\Http\Controllers;

use App\Exceptions\ProblemException;
use App\Http\Requests\SourceRefsRequest;
use App\Lib\Deploy\Source\GitRefs;
use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\Vault\RequestVault;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The branches and tags of a remote repository, before any project exists.
 *
 * The onboarding branch picker cannot use the project branch list: that needs a
 * checkout. This reads the remote directly, over the same ls-remote the
 * create-time probe runs, and writes nothing.
 */
class SourceRefsController extends Controller
{
    private const DEFAULT_LIMIT = 200;

    #[OA\Post(
        path: '/source/refs',
        summary: 'List the branches and tags of a remote Git repository',
        description: 'Reads a remote with `git ls-remote --symref`, without cloning it or '
            .'touching any account, so a branch picker can be shown before a project exists. '
            .'Runs globally, not for one project. `branches` is the default branch first, then '
            .'by name; `tags` is by name. `default_branch` is the branch HEAD pointed at, or null '
            .'when the remote named none. A public repository needs no token; a private one takes a '
            .'`git_token` (a `vault:<key>` is accepted in its place). A repository URL whose host '
            .'resolves to an internal address, or does not resolve at all, is refused 422 with '
            .'`problems[{field: repository, code: host_refused}]`.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repository'],
            properties: [
                new OA\Property(
                    property: 'repository',
                    type: 'string',
                    description: 'Repository URL. The scheme may be left out; the host is not guessed '
                        .'for owner/repo shorthand.',
                    example: 'https://github.com/owner/repo.git'
                ),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    description: 'Access token for a private HTTPS repository. A `vault:<key>` of a '
                        .'global vault entry is accepted in place of the literal token; a project entry '
                        .'is accepted while no project owns it, and listing does not assign it. Omitted, '
                        .'the remote is read anonymously.'
                ),
                new OA\Property(
                    property: 'limit',
                    type: 'integer',
                    minimum: 1,
                    maximum: 1000,
                    description: 'Most branches and, separately, most tags to return. Default 200.',
                    example: 200
                ),
            ],
        )),
        tags: ['Deploy'],
        responses: [
            new OA\Response(response: 200, description: 'The remote refs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SourceRefs')],
            )),
            new OA\Response(response: 422, description: 'Repository refused or not found', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 502, description: 'The remote could not be reached', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'remote_unreachable'),
                ],
            )),
        ],
    )]
    public function refs(SourceRefsRequest $request, GitRemoteProbe $probe): JsonResponse
    {
        $params = $request->validated();
        $repository = (string) $params['repository'];
        $token = RequestVault::get('git_token', null);

        $this->refuseRefusedHost($repository);

        try {
            $refs = $probe->refs($repository, is_string($token) ? $token : null);
        } catch (GitRefsUnavailableException $e) {
            return $this->refsFailure($e);
        }

        return new JsonResponse(['data' => $this->refsData($refs, $repository, $this->limit($params))]);
    }

    /** @throws ProblemException */
    private function refuseRefusedHost(string $repository): void
    {
        $problem = RepositoryAddress::problem($repository, 'repository');
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    private function refsFailure(GitRefsUnavailableException $e): JsonResponse
    {
        if ($e->outcome === GitRefsUnavailableException::NOT_FOUND) {
            throw ProblemException::one('repository', 'repository_not_found', $e->getMessage());
        }
        if ($e->outcome === GitRefsUnavailableException::AUTHENTICATION_FAILED) {
            throw ProblemException::one('git_token', 'authentication_failed', $e->getMessage());
        }

        return new JsonResponse([
            'message' => $e->getMessage(),
            'code' => GitRefsUnavailableException::UNREACHABLE,
        ], 502);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function limit(array $params): int
    {
        $limit = $params['limit'] ?? self::DEFAULT_LIMIT;

        return is_numeric($limit) ? max(1, (int) $limit) : self::DEFAULT_LIMIT;
    }

    /** @return array<string, mixed> */
    private function refsData(GitRefs $refs, string $repository, int $limit): array
    {
        $branches = $refs->sortedBranches();
        $tags = $refs->sortedTags();

        return [
            'repository' => $repository,
            'default_branch' => $refs->defaultBranch,
            'branches' => array_slice($branches, 0, $limit),
            'tags' => array_slice($tags, 0, $limit),
            'truncated' => count($branches) > $limit || count($tags) > $limit,
        ];
    }
}
