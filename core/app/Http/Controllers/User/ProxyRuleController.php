<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProxyRuleResource;
use App\System;
use App\Models\ProxyRule;
use App\Rules\ListenIp;
use App\Rules\ProxyServerName;
use App\Rules\UpstreamHost;
use App\System\Services\Webserver\ProxyListenPort;
use App\System\Services\Webserver\ProxyRuleServerName;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Resources\ProxyRuleCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ProxyRuleController extends Controller
{
    private const DUPLICATE_RULE = 'A rule with the same transport, port, and server_name already exists';

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
        $rule = $this->ruleOr404($id);

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
                new OA\Property(property: 'owner_scope', type: 'string', enum: ['system', 'user'], nullable: true, description: "Who owns the rule. Defaults to user. A user rule reaches its own project's app only; a system rule is the operator's and may point anywhere the host reaches."),
                new OA\Property(property: 'username', type: 'string', nullable: true, description: 'Project the rule belongs to. Required when owner_scope is user.'),
                new OA\Property(property: 'transport', type: 'string', enum: ['http', 'tcp', 'udp']),
                new OA\Property(property: 'listen_ip', type: 'string', nullable: true),
                new OA\Property(property: 'listen_port', type: 'integer', example: 3000),
                new OA\Property(property: 'server_name', type: 'string', nullable: true, description: "For a user rule, empty or one of the project's own domains or aliases. A system rule may name any host."),
                new OA\Property(property: 'upstream_host', type: 'string', example: 'shop', description: "For a user rule, the project's own name. A system rule may name any host or address."),
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
        $validated = $request->validate(self::newRuleRules());

        $ownerScope = $validated['owner_scope'] ?? $request->input('owner_scope', 'user');
        $username = $this->ownerUsername($ownerScope, $validated['username'] ?? $request->input('username'));
        $this->refuseForeignUpstream($ownerScope, $username, $validated['upstream_host']);

        if (in_array($validated['transport'], ['tcp', 'udp'])) {
            $validated['server_name'] = null;
            $validated['upstream_protocol'] = null;
        }
        $this->refuseForeignServerName($ownerScope, $username, $validated['server_name'] ?? null);

        if ($validated['enabled'] ?? true) {
            $this->refuseClashingRule($validated);
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

        $this->rebuildProxyConfig();

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
                new OA\Property(property: 'upstream_host', type: 'string', nullable: true, description: "For a user rule, the project's own name. A system rule may name any host or address."),
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
        $rule = $this->ruleOr404($id);

        $validated = $request->validate([
            'upstream_host' => ['nullable', 'string', new UpstreamHost()],
            'upstream_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (($validated['enabled'] ?? false) && !$rule->enabled) {
            $this->refuseReenabling($rule);
        }
        if ($validated['enabled'] ?? $rule->enabled) {
            $this->refuseForeignTargets($rule, $validated['upstream_host'] ?? $rule->upstream_host);
        }

        $rule->update($validated);

        $this->rebuildProxyConfig();

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
        $rule = $this->ruleOr404($id);
        $rule->delete();

        $this->rebuildProxyConfig();

        return new ProxyRuleResource($rule);
    }

    private function ruleOr404(int $id): ProxyRule
    {
        $rule = ProxyRule::find($id);
        if (!$rule) {
            abort(new JsonResponse([
                'message' => 'Rule not found',
            ], 404));
        }

        return $rule;
    }

    private function rebuildProxyConfig(): void
    {
        $system = app(System::class);
        $system->webserver()->rebuildConfig();
        $system->webserver()->scheduleWebserverReloadInBackground();
    }

    /**
     * The address and name fields are written into the shared nginx-proxy config verbatim.
     *
     * @return array<string, list<mixed>>
     */
    private static function newRuleRules(): array
    {
        return [
            'owner_scope' => ['nullable', Rule::in(['system', 'user'])],
            'username' => ['nullable', 'string', 'exists:users,username'],
            'transport' => ['required', Rule::in(['http', 'tcp', 'udp'])],
            'listen_ip' => ['nullable', 'string', new ListenIp()],
            'listen_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'server_name' => ['nullable', 'string', new ProxyServerName()],
            'upstream_host' => ['required', 'string', new UpstreamHost()],
            'upstream_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * A system rule has no owner; a user rule must name its project, so deleting the project
     * (ProxyRule::forUser, the FK cascade) removes it.
     *
     * @throws ValidationException
     */
    private function ownerUsername(mixed $ownerScope, mixed $username): mixed
    {
        if ($ownerScope === 'system') {
            return null;
        }
        if (empty($username)) {
            throw ValidationException::withMessages([
                'username' => 'username is required when owner_scope is user',
            ]);
        }

        return $username;
    }

    /**
     * Checks an enabled new rule against the rules already live and the host's ports. A disabled
     * one is stored unchecked and checked when switched on, in update().
     *
     * @param array<string, mixed> $validated
     * @throws ValidationException
     */
    private function refuseClashingRule(array $validated): void
    {
        if ($validated['transport'] === 'http') {
            $this->refuseDuplicateHttpRule($validated['server_name'] ?? null, (int) $validated['listen_port']);
        } else {
            $this->refuseDuplicateStreamRule($validated);
        }

        $this->refuseUnusablePort($validated['transport'], $validated['listen_ip'] ?? '*', $validated['listen_port']);
    }

    /**
     * Keyed on listen_port, or the 422 would name field 0 instead of the port the twin already holds.
     *
     * @param array<string, mixed> $validated
     */
    private function refuseDuplicateStreamRule(array $validated): void
    {
        if (ProxyRule::query()
            ->where('enabled', true)
            ->where('transport', $validated['transport'])
            ->where('listen_port', $validated['listen_port'])
            ->where('listen_ip', $validated['listen_ip'] ?? '*')
            ->where('server_name', $validated['server_name'] ?? null)
            ->exists()
        ) {
            throw ValidationException::withMessages(['listen_port' => self::DUPLICATE_RULE]);
        }
    }

    /** Re-enabling a disabled rule runs the checks a fresh one gets. */
    private function refuseReenabling(ProxyRule $rule): void
    {
        $this->refuseUnusablePort($rule->transport, $rule->listen_ip ?? '*', $rule->listen_port);
        if ($rule->transport === 'http') {
            $this->refuseDuplicateHttpRule($rule->server_name, (int) $rule->listen_port, (int) $rule->id);
        }
    }

    /** A live rule, one stored before these checks included, must be fixed or switched off. */
    private function refuseForeignTargets(ProxyRule $rule, string $upstreamHost): void
    {
        $this->refuseForeignUpstream($rule->owner_scope, $rule->username, $upstreamHost);
        $this->refuseForeignServerName($rule->owner_scope, $rule->username, $rule->server_name);
    }

    /** 422 for a port the engine or something else on the host already listens on. */
    private function refuseUnusablePort(string $transport, string $listenIp, int $port): void
    {
        $refusal = (new ProxyListenPort(app(System::class)))->refusal($transport, $listenIp, $port);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['listen_port' => $refusal]);
        }
    }

    /** 422 for an upstream a project's rule may not reach. */
    private function refuseForeignUpstream(?string $ownerScope, ?string $username, string $upstreamHost): void
    {
        $refusal = ProxyRuleUpstream::refusal($ownerScope, $username, $upstreamHost);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['upstream_host' => $refusal]);
        }
    }

    /**
     * 422 for a second enabled HTTP rule on one name and port, across every
     * listen IP and owner: the vhost renders one entry per port.
     */
    private function refuseDuplicateHttpRule(?string $serverName, int $listenPort, ?int $ignoreId = null): void
    {
        if (ProxyRule::conflictingHttpRule($serverName, $listenPort, $ignoreId) !== null) {
            throw ValidationException::withMessages(['listen_port' => self::DUPLICATE_RULE]);
        }
    }

    /** 422 for another project's domain on a project's rule. */
    private function refuseForeignServerName(?string $ownerScope, ?string $username, ?string $serverName): void
    {
        $refusal = ProxyRuleServerName::refusal($ownerScope, $username, $serverName);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['server_name' => $refusal]);
        }
    }

}
