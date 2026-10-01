<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\DeployHookCreateRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Lib\DeployHook\DeployHookActions;
use App\Lib\DeployHook\DeployHookNotFound;
use App\Models\HookDelivery;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The authenticated side of Deploy Hooks: what a client calls to get the URL
 * and secret it then registers in its git host, and to look at, replace or
 * remove them. The public side, which the git host calls, is
 * {@see \App\Http\Controllers\Web\HookController}.
 *
 * One hook per checkout, selected by the optional `path` exactly as the git
 * tools select a checkout: absent means the Deploy-managed one.
 */
class DeployHookController extends Controller
{
    /**
     * Create the Deploy Hook for a checkout (default: the Deploy-managed one).
     *
     * 201 with the URL and, this once, the secret. Asking again is 200 with
     * the same URL and no secret -- so it is safe to call to find out whether
     * a hook exists, and it never rotates one that does.
     */
    #[OA\Post(
        path: '/projects/{username}/git/deploy-hook',
        description: 'Create the Deploy Hook for a git-connected checkout, so a push to its tracked branch '
            . 'redeploys the project. Optional `path` defaults to `project` on DinD and `public_html` on '
            . 'FPM/LiteSpeed (`project` is the Deploy-managed checkout). Returns the `url` to register in the git host and the `secret` to sign with. '
            . 'The secret is shown ONCE, in this response, and can never be read again: store it now, or '
            . 'rotate later. Asking again returns the same hook (200) without the secret and never rotates it. '
            . 'On the Deploy-managed checkout every push to the tracked branch force-updates it to match the '
            . 'repository (local changes to tracked files and untracked files that are not engine-managed are '
            . 'discarded before the rebuild) and the response carries a `warning` saying so. On a Site Git '
            . 'checkout (e.g. `public_html`) a push only fast-forwards, with no build: untracked files such as '
            . 'uploads are left alone, and when git refuses because of a local change, an untracked file at an '
            . 'incoming path or rewritten history, nothing changes and the delivery\'s result is `pull_refused`. '
            . 'The response\'s `tls` block says whether the engine\'s certificate is one a git host trusts outright '
            . '(`state: valid`) or self-signed (`state: self_signed`); when self-signed it carries `instructions`, '
            . 'one paragraph per supported provider (github, gitlab, bitbucket-cloud, bitbucket-data-center) on '
            . 'making that provider call the hook anyway. Optional `provider` on the request narrows `instructions` '
            . 'to that one provider. `tls.warning` is set when the engine has no public address, so a git host on '
            . 'the internet could not reach the hook at all. '
            . '422 when the checkout is not connected to git.',
        summary: 'Create a push-to-deploy hook',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'provider', description: 'Optional. Narrows `tls.instructions` to this git host: github, gitlab, bitbucket-cloud, bitbucket-data-center.', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Created; `secret` is present this once', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'secret', type: 'string'),
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'created', type: 'boolean'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'warning', type: 'string'),
                    new OA\Property(property: 'tls', type: 'object', properties: [
                        new OA\Property(property: 'state', type: 'string', enum: ['valid', 'self_signed']),
                        new OA\Property(property: 'warning', type: 'string', nullable: true),
                        new OA\Property(property: 'instructions', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string')),
                    ]),
                ])],
            )),
            new OA\Response(response: 200, description: 'The hook already existed; no `secret`', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The checkout is not connected to git', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function create(string $username, DeployHookCreateRequest $request, DeployHookActions $hooks): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(function () use ($hooks, $user, $request): JsonResponse {
            $data = $hooks->create($user, $request->validated());

            return new JsonResponse(['data' => $data], $data['created'] ? 201 : 200);
        });
    }

    /**
     * What the checkout's hook is: its URL and when it was made. Never the
     * secret -- there is nothing to read it back with, on purpose.
     */
    #[OA\Get(
        path: '/projects/{username}/git/deploy-hook',
        description: 'Show the Deploy Hook of a checkout: its `url` and when it was created or last rotated. '
            . 'Never includes the secret, which is shown only when the hook is created or rotated. '
            . '`registered_url` is the address this hook was last created or rotated under; '
            . '`url_changed_since_registration` is true once the engine\'s own address has moved on from it (an IP a '
            . 'domain certificate replaced), meaning the git host still calls `registered_url` and the hook has to '
            . 'be re-registered at `url`. `tls` reports the same certificate state POST .../deploy-hook does. '
            . '`deliveries` lists the ' . HookDelivery::KEEP_HISTORY . ' most recent accepted Hook Deliveries '
            . 'and the ' . HookDelivery::KEEP_REJECTED . ' most recent rejected ones, newest first:`outcome` is what the request was answered with (queued, ignored, rejected, coalesced) '
            . 'and `result` is what the queued work then reached (deployed, partial, deploy_failed, pull_refused, superseded), '
            . 'null while still queued or for a delivery that queued nothing. `detail` carries the reason for a '
            . 'refused pull or a failure; `deploy_id` points at the full build log '
            . '(`php artisan project:deploy:log <project> --id=<deploy_id>`) for a delivery that started one. '
            . 'Optional `path` defaults to `project` on DinD and `public_html` on FPM/LiteSpeed. '
            . '404 when the checkout has no hook.',
        summary: 'Show a push-to-deploy hook',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The hook (no secret)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'warning', type: 'string'),
                    new OA\Property(property: 'registered_url', type: 'string', nullable: true),
                    new OA\Property(property: 'url_changed_since_registration', type: 'boolean'),
                    new OA\Property(property: 'tls', type: 'object', properties: [
                        new OA\Property(property: 'state', type: 'string', enum: ['valid', 'self_signed']),
                        new OA\Property(property: 'warning', type: 'string', nullable: true),
                        new OA\Property(property: 'instructions', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string')),
                    ]),
                    new OA\Property(property: 'deliveries', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'provider', type: 'string'),
                        new OA\Property(property: 'event', type: 'string', nullable: true),
                        new OA\Property(property: 'branch', type: 'string', nullable: true),
                        new OA\Property(property: 'commit', type: 'string', nullable: true),
                        new OA\Property(property: 'outcome', type: 'string'),
                        new OA\Property(property: 'reason', type: 'string', nullable: true),
                        new OA\Property(property: 'result', type: 'string', nullable: true),
                        new OA\Property(property: 'detail', type: 'string', nullable: true),
                        new OA\Property(property: 'deploy_id', type: 'string', nullable: true),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ])),
                ])],
            )),
            new OA\Response(response: 404, description: 'User or hook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function show(string $username, GitPathRequest $request, DeployHookActions $hooks): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => new JsonResponse(['data' => $hooks->show($user, $request->validated())]));
    }

    #[OA\Post(
        path: '/projects/{username}/git/deploy-hook/rotate',
        description: 'Replace the Deploy Hook\'s URL and secret with new ones, for when either leaked. '
            . 'The old URL answers 404 from this moment, so the new `url` and `secret` must be registered '
            . 'in the git host again. The secret is shown ONCE, in this response, and can never be read again. '
            . 'Optional `path` defaults to `project` on DinD and `public_html` on FPM/LiteSpeed. '
            . '404 when the checkout has no hook; rotating '
            . 'never creates one. On the Deploy-managed checkout every push to the tracked branch force-updates '
            . 'it to match the repository (local changes to tracked files and untracked files that are not '
            . 'engine-managed are discarded before the rebuild); a Site Git checkout is only fast-forwarded.',
        summary: 'Rotate a push-to-deploy hook',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', type: 'string')],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Rotated; the new `secret` is in this response only', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'secret', type: 'string'),
                    new OA\Property(property: 'path', type: 'string'),
                    new OA\Property(property: 'rotated', type: 'boolean'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'warning', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'User or hook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function rotate(string $username, GitPathRequest $request, DeployHookActions $hooks): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => new JsonResponse(['data' => $hooks->rotate($user, $request->validated())]));
    }

    #[OA\Delete(
        path: '/projects/{username}/git/deploy-hook',
        description: 'Delete the Deploy Hook of a checkout and its delivery history. Its URL answers 404 '
            . 'from then on; remove the webhook in the git host too. Optional `path` defaults to `project` '
            . 'on DinD and `public_html` on FPM/LiteSpeed. 404 when the checkout has no hook.',
        summary: 'Delete a push-to-deploy hook',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or `public_html` (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'User or hook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function destroy(string $username, GitPathRequest $request, DeployHookActions $hooks): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(function () use ($hooks, $user, $request): JsonResponse {
            $hooks->delete($user, $request->validated());

            return new JsonResponse(null, 204);
        });
    }

    /** @param callable(): JsonResponse $respond */
    private function respond(callable $respond): JsonResponse
    {
        try {
            return $respond();
        } catch (DeployHookNotFound $e) {
            return new JsonResponse(['message' => $e->getMessage()], 404);
        } catch (GitException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->httpStatus);
        }
    }
}
