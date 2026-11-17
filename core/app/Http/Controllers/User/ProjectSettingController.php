<?php

namespace App\Http\Controllers\User;

use App\Auth\RequestToken;
use App\Http\Controllers\Controller;
use App\Http\Responses\Refusal;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\System\Project\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Per-project settings the engine needs but the project cannot carry in its
 * own files -- a Cloudflare API token, today.
 *
 * Every read and write goes through {@see Settings}, which is what the
 * `project:settings:*` console commands call, so validation, the Cloudflare
 * account check on write and the teardown rules on unset are the same
 * whichever way the value arrives.
 *
 * A secret is never read back: {@see Settings::get()} redacts it, so
 * `set` tells a caller whether the value works and `get` tells it whether one
 * is present, and neither hands it out again.
 */
class ProjectSettingController extends Controller
{
    public const READ_ONLY = 'setting_read_only';

    #[OA\Get(
        path: '/projects/{username}/settings',
        summary: "List a project's settings",
        description: 'Secret values are redacted; `set` says whether a value is present.',
        security: [['bearerAuth' => []]],
        tags: ['Project Settings'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectSetting'))],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * List every known setting for a project.
     */
    public function index(string $username): JsonResponse
    {
        return new JsonResponse(['data' => $this->settingsFor($username)->list()]);
    }

    #[OA\Get(
        path: '/projects/{username}/settings/{key}',
        summary: 'Get one project setting',
        description: 'A secret comes back redacted, never in full.',
        security: [['bearerAuth' => []]],
        tags: ['Project Settings'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'cloudflare-api-token'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The setting', content: new OA\JsonContent(ref: '#/components/schemas/ProjectSetting')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown setting', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Get one setting.
     */
    public function show(string $username, string $key): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $this->settingsFor($username)->get($key)]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['key' => $e->getMessage()]);
        }
    }

    #[OA\Put(
        path: '/projects/{username}/settings/{key}',
        summary: 'Set one project setting',
        description: 'A Cloudflare API token is verified against Cloudflare before it is stored, and the '
            .'account it belongs to comes back in the response.',
        security: [['bearerAuth' => []]],
        tags: ['Project Settings'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'cloudflare-api-token'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['value'],
            properties: [new OA\Property(property: 'value', type: 'string', description: 'The value to store.')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Setting stored', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Cloudflare API token saved.'),
                    new OA\Property(property: 'account_id', type: 'string'),
                    new OA\Property(property: 'account_name', type: 'string'),
                ],
            )),
            new OA\Response(response: 403, description: 'The `protected` setting is read-only over the API', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Set one setting.
     */
    public function update(string $username, string $key, Request $request): JsonResponse
    {
        if ($refusal = $this->readOnlyRefusal($request, $username, $key)) {
            return $refusal;
        }

        $settings = $this->settingsFor($username);
        $validated = $request->validate(['value' => ['required', 'string']]);

        // A secret setting may be given a `vault:<key>` in place of the secret.
        try {
            $result = $settings->set($key, Settings::resolveValue($username, $key, (string) $validated['value']));
        } catch (\InvalidArgumentException|CloudflareException $e) {
            // An unknown key, or a token Cloudflare itself refused: the
            // caller's input either way.
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        return new JsonResponse(['data' => $result]);
    }

    #[OA\Delete(
        path: '/projects/{username}/settings/{key}',
        summary: 'Clear one project setting',
        description: 'Clearing a Cloudflare API token is refused while the project still has Cloudflare '
            .'tunnels, unless `force` is true, which tears them down first. '
            .'The stored value goes and is not kept anywhere; this cannot be undone. Ask the operator to confirm '
            .'first.',
        security: [['bearerAuth' => []]],
        tags: ['Project Settings'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'cloudflare-api-token'),
            new OA\Parameter(name: 'force', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Tear down Cloudflare tunnels that depend on this value.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Setting cleared', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)],
            )),
            new OA\Response(response: 403, description: 'The `protected` setting is read-only over the API', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Clear one setting.
     */
    public function destroy(string $username, string $key, Request $request): JsonResponse
    {
        if ($refusal = $this->readOnlyRefusal($request, $username, $key)) {
            return $refusal;
        }

        $force = $request->boolean('force');

        try {
            $this->settingsFor($username)->unset($key, $force);
        } catch (\InvalidArgumentException|CloudflareException $e) {
            throw ValidationException::withMessages(['key' => $e->getMessage()]);
        }

        return new JsonResponse(['success' => true]);
    }

    private function settingsFor(string $username): Settings
    {
        $user = $this->projectOr404($username);

        return $user->project()->settings();
    }

    /**
     * `protected` and `system` are the operator's: a token able to clear one would lift the
     * refusal it is being held by. The console writes them without a token.
     */
    private function readOnlyRefusal(Request $request, string $username, string $key): ?JsonResponse
    {
        $key = Settings::normalizeKey($key);
        if (! in_array($key, Settings::OPERATOR_KEYS, true) || RequestToken::of($request) === null) {
            return null;
        }

        return Refusal::json(403, 'key', self::READ_ONLY, "The '{$key}' setting is read-only over the API; an operator "
            .'sets or clears it on the host.', $username);
    }
}
