<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProxyRuleResource;
use App\System;
use App\Models\ProxyRule;
use App\Rules\ListenIp;
use App\Rules\ProxyServerName;
use App\Rules\UpstreamHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Resources\ProxyRuleCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ProxyRuleController extends Controller
{
    #[OA\Get(
        path: '/proxy-rules',
        summary: 'List all proxy rules',
        security: [['bearerAuth' => []]],
        tags: ['Proxy Rules'],
        responses: [
            new OA\Response(response: 200, description: 'List of proxy rules', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProxyRule'))],
            )),
        ],
    )]
    /**
     * List all proxy rules.
     */
    public function index(Request $request): ProxyRuleCollection
    {
        /** @var Collection<array-key, ProxyRule> $rules */
        $rules = ProxyRule::query()
            ->orderBy('transport')
            ->orderBy('listen_port')
            ->orderBy('server_name')
            ->get();

        return new ProxyRuleCollection($rules);
    }

    #[OA\Get(
        path: '/proxy-rules/{id}',
        summary: 'Get a proxy rule',
        security: [['bearerAuth' => []]],
        tags: ['Proxy Rules'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Proxy rule', content: new OA\JsonContent(ref: '#/components/schemas/ProxyRule')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * Show a single proxy rule.
     */
    public function show(int $id): ProxyRuleResource
    {
        $rule = ProxyRule::find($id);
        if (!$rule) {
            abort(new JsonResponse([
                'message' => 'Rule not found',
            ], 404));
        }

        return new ProxyRuleResource($rule);
    }

    #[OA\Post(
        path: '/proxy-rules',
        summary: 'Create a proxy rule',
        security: [['bearerAuth' => []]],
        tags: ['Proxy Rules'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['transport', 'listen_port', 'upstream_host', 'upstream_port'],
            properties: [
                new OA\Property(property: 'owner_scope', type: 'string', enum: ['system', 'user'], nullable: true, description: 'Who owns the rule. Defaults to user.'),
                new OA\Property(property: 'username', type: 'string', nullable: true, description: 'Project the rule belongs to. Required when owner_scope is user.'),
                new OA\Property(property: 'transport', type: 'string', enum: ['http', 'tcp', 'udp']),
                new OA\Property(property: 'listen_ip', type: 'string', nullable: true),
                new OA\Property(property: 'listen_port', type: 'integer', example: 3000),
                new OA\Property(property: 'server_name', type: 'string', nullable: true),
                new OA\Property(property: 'upstream_host', type: 'string', example: '127.0.0.1'),
                new OA\Property(property: 'upstream_port', type: 'integer', example: 3001),
                new OA\Property(property: 'upstream_protocol', type: 'string', nullable: true),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                new OA\Property(property: 'metadata', type: 'object', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Proxy rule created', content: new OA\JsonContent(ref: '#/components/schemas/ProxyRule')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Create a new proxy rule.
     */
    public function store(Request $request): ProxyRuleResource
    {
        /**
         * @var array{
         *   transport: string,
         *   listen_ip?: ?string,
         *   listen_port: int,
         *   server_name?: ?string,
         *   upstream_host: string,
         *   upstream_port: int,
         *   upstream_protocol?: ?string,
         *   enabled: bool,
         *   metadata?: ?array
         * } $validated
         */
        $validated = $request->validate([
            'owner_scope' => ['nullable', Rule::in(['system', 'user'])],
            'username' => ['nullable', 'string', 'exists:users,username'],
            'transport' => ['required', Rule::in(['http', 'tcp', 'udp'])],
            // These four are written into the shared nginx-proxy config verbatim.
            'listen_ip' => ['nullable', 'string', new ListenIp()],
            'listen_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'server_name' => ['nullable', 'string', new ProxyServerName()],
            'upstream_host' => ['required', 'string', new UpstreamHost()],
            'upstream_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $ownerScope = $validated['owner_scope'] ?? $request->input('owner_scope', 'user');
        $username = $validated['username'] ?? $request->input('username');
        if ($ownerScope === 'system') {
            $username = null;
        } elseif (empty($username)) {
            // User-owned rules must be tied to a project username so deletion
            // (ProxyRule::forUser / FK cascade) can remove them.
            throw ValidationException::withMessages([
                'username' => 'username is required when owner_scope is user',
            ]);
        }

        // Validation: HTTP rules should have server_name be either null or valid
        if ($validated['transport'] === 'http' && !empty($validated['server_name'])) {
            if (!$this->isValidServerName($validated['server_name'])) {
                throw ValidationException::withMessages([
                    'server_name' => 'server_name must be a valid domain or wildcard'
                ]);
            }
        }

        // Validation: stream rules should not have server_name
        if (in_array($validated['transport'], ['tcp', 'udp'])) {
            $validated['server_name'] = null;
            $validated['upstream_protocol'] = null;
        }

        // Check for conflicts with existing rules
        /** @var ?ProxyRule $existing */
        $existing = ProxyRule::query()
            ->where('enabled', true)
            ->where('transport', $validated['transport'])
            ->where('listen_port', $validated['listen_port'])
            ->where('listen_ip', $validated['listen_ip'] ?? '*')
            ->where('server_name', $validated['server_name'] ?? null)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'A rule with the same transport, port, and server_name already exists'
            ]);
        }

        /** @var ProxyRule */
        $rule = ProxyRule::create([
            'owner_scope' => $ownerScope,
            'username' => $username,
            'transport' => $validated['transport'],
            'listen_ip' => $validated['listen_ip'] ?? '*',
            'listen_port' => $validated['listen_port'],
            'server_name' => $validated['server_name'] ?? null,
            'upstream_host' => $validated['upstream_host'],
            'upstream_port' => $validated['upstream_port'],
            'upstream_protocol' => $validated['upstream_protocol'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
            'is_generated' => false,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $system = new System();
        $system->webserver()->rebuildConfig();
        $system->webserver()->scheduleWebserverReloadInBackground();

        return new ProxyRuleResource($rule);
    }

    #[OA\Put(
        path: '/proxy-rules/{id}',
        summary: 'Update a proxy rule',
        security: [['bearerAuth' => []]],
        tags: ['Proxy Rules'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'upstream_host', type: 'string', nullable: true),
                new OA\Property(property: 'upstream_port', type: 'integer', nullable: true),
                new OA\Property(property: 'upstream_protocol', type: 'string', nullable: true),
                new OA\Property(property: 'enabled', type: 'boolean', nullable: true),
                new OA\Property(property: 'metadata', type: 'object', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Proxy rule updated', content: new OA\JsonContent(ref: '#/components/schemas/ProxyRule')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * Update a proxy rule.
     */
    public function update(int $id, Request $request): ProxyRuleResource
    {
        $rule = ProxyRule::find($id);
        if (!$rule) {
            abort(new JsonResponse([
                'message' => 'Rule not found',
            ], 404));
        }


        $validated = $request->validate([
            'upstream_host' => ['nullable', 'string', new UpstreamHost()],
            'upstream_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $rule->update($validated);

        $system = new System();
        $system->webserver()->rebuildConfig();
        $system->webserver()->scheduleWebserverReloadInBackground();

        return new ProxyRuleResource($rule);
    }

    #[OA\Delete(
        path: '/proxy-rules/{id}',
        summary: 'Delete a proxy rule',
        security: [['bearerAuth' => []]],
        tags: ['Proxy Rules'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Proxy rule deleted', content: new OA\JsonContent(ref: '#/components/schemas/ProxyRule')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * Delete a proxy rule.
     */
    public function destroy(int $id): ProxyRuleResource
    {
        $rule = ProxyRule::find($id);
        if (!$rule) {
            abort(new JsonResponse([
                'message' => 'Rule not found',
            ], 404));
        }
        $rule->delete();

        $system = new System();
        $system->webserver()->rebuildConfig();
        $system->webserver()->scheduleWebserverReloadInBackground();

        return new ProxyRuleResource($rule);
    }

    /**
     * Validate server_name format.
     */
    private function isValidServerName(string $name): bool
    {
        if ($name === '*' || strpos($name, '*') === 0) {
            return true;
        }
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $name) === 1;
    }
}
