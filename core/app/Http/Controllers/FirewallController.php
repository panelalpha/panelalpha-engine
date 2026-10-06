<?php

namespace App\Http\Controllers;

use App\Http\Requests\Firewall\FirewallRuleRequest;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\FirewallNotFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The host firewall, through whichever provider FIREWALL_PROVIDER names. */
class FirewallController extends Controller
{
    private function firewall(): Firewall
    {
        return FirewallFactory::default();
    }

    #[OA\Get(
        path: '/firewall/status',
        summary: 'Get the firewall status',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        responses: [
            new OA\Response(response: 200, description: 'Firewall status', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'provider', type: 'string', example: 'ufw'),
                    new OA\Property(property: 'enabled', type: 'boolean', nullable: true, description: 'null when the firewall could not be read; see error'),
                    new OA\Property(property: 'version', type: 'string', nullable: true),
                    new OA\Property(property: 'default_incoming', type: 'string', nullable: true, example: 'deny'),
                    new OA\Property(property: 'default_outgoing', type: 'string', nullable: true, example: 'allow'),
                    new OA\Property(property: 'error', type: 'string', nullable: true),
                ])],
            )),
        ],
    )]
    public function status(): JsonResponse
    {
        return new JsonResponse(['data' => $this->firewall()->status()->toArray()]);
    }

    #[OA\Get(
        path: '/firewall/rules',
        summary: 'List firewall rules',
        description: 'In the order the firewall evaluates them. Rules marked managed are the ports the engine itself needs. '
            . 'Host rules by default; scope=published lists the rules for ports Docker publishes. A deny in both scopes is in both lists, once, with scope both.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        parameters: [
            new OA\Parameter(name: 'scope', in: 'query', required: false, description: 'host: the host\'s own ports. published: ports Docker publishes, matched on the container\'s port.', schema: new OA\Schema(type: 'string', default: 'host', enum: ['host', 'published'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Firewall rules', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FirewallRule'))],
            )),
        ],
    )]
    public function rules(Request $request): JsonResponse
    {
        $scope = $request->validate(['scope' => ['nullable', 'string', 'in:' . FirewallRule::HOST . ',' . FirewallRule::PUBLISHED]])['scope'] ?? FirewallRule::HOST;
        $rules = array_filter($this->firewall()->rules(), static fn (FirewallRule $rule): bool => in_array($rule->scope, [$scope, FirewallRule::BOTH], true));

        return new JsonResponse([
            'data' => array_values(array_map(static fn (FirewallRule $rule): array => $rule->toArray(), $rules)),
        ]);
    }

    #[OA\Post(
        path: '/firewall/rules',
        summary: 'Add a firewall rule',
        description: 'A deny rule is placed above every allow rule, so it wins; an allow rule goes last. '
            . 'An inbound deny covers the host\'s ports and the ports Docker publishes (scope both, as a fail2ban ban does) unless scope published is asked for. '
            . 'A rule needs a port, a source or a destination. A rule that matches the same traffic as one already there, '
            . 'whatever its action or comment, is refused naming that rule, which is left as it is. Do not open a port for an application: '
            . 'sites are reached through the engine\'s webserver.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['action'],
            properties: [
                new OA\Property(property: 'action', type: 'string', enum: ['allow', 'deny']),
                new OA\Property(property: 'scope', type: 'string', enum: ['host', 'published', 'both'], nullable: true, description: 'Default host: the host\'s own ports. published: ports Docker publishes; port and destination are the container\'s, and direction is in. Neither scope reaches the other. An inbound deny without scope published is both.'),
                new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out', 'both'], nullable: true, description: 'Default in. both: traffic from source coming in and to source going out, as one rule; needs source and no destination.'),
                new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], nullable: true, description: 'Omit for both. Required with a port range or list.'),
                new OA\Property(property: 'port', type: 'string', nullable: true, example: '22', description: 'Destination port, range (30000:30009) or comma list.'),
                new OA\Property(property: 'source', type: 'string', nullable: true, example: '203.0.113.7', description: 'IPv4/IPv6 address or CIDR. Omit for any.'),
                new OA\Property(property: 'destination', type: 'string', nullable: true, description: 'IPv4/IPv6 address or CIDR. Omit for any.'),
                new OA\Property(property: 'comment', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Rule added', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FirewallRule')],
            )),
        ],
    )]
    public function addRule(FirewallRuleRequest $request): JsonResponse
    {
        /** @var array{action: string} $data */
        $data = $request->rule();
        $rule = $this->attempt(fn () => $this->firewall()->addRule(FirewallRule::fromArray($data)));

        return new JsonResponse(['data' => $rule->toArray()]);
    }

    #[OA\Put(
        path: '/firewall/rules/{id}',
        summary: 'Edit a firewall rule',
        description: 'A field not sent keeps its current value; send null to clear it. The rule gets a new id when what it matches changes; '
            . 'an edit that would make it match the same traffic as another rule is refused.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'action', type: 'string', enum: ['allow', 'deny']),
                new OA\Property(property: 'scope', type: 'string', enum: ['host', 'published', 'both'], nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out', 'both'], nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'port', type: 'string', nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'source', type: 'string', nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'destination', type: 'string', nullable: true, x: ['mcp-nullable' => true]),
                new OA\Property(property: 'comment', type: 'string', nullable: true, x: ['mcp-nullable' => true]),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FirewallRule')],
            )),
        ],
    )]
    public function updateRule(string $id, FirewallRuleRequest $request): JsonResponse
    {
        $current = $this->changeable($id);
        $rule = $current->with($request->rule());
        if ($rule->problems() !== []) {
            throw ValidationException::withMessages($rule->problems());
        }

        $updated = $this->attempt(fn () => $this->firewall()->updateRule($id, $rule));

        return new JsonResponse(['data' => $updated->toArray()]);
    }

    #[OA\Delete(
        path: '/firewall/rules/{id}',
        summary: 'Delete a firewall rule',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Rule deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FirewallRule')],
            )),
        ],
    )]
    public function deleteRule(string $id): JsonResponse
    {
        $this->changeable($id);
        $rule = $this->attempt(fn () => $this->firewall()->deleteRule($id));

        return new JsonResponse(['data' => $rule->toArray()]);
    }

    #[OA\Put(
        path: '/firewall/enable',
        summary: 'Enable the firewall',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        responses: [new OA\Response(response: 200, description: 'Firewall enabled', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))],
    )]
    public function enable(): JsonResponse
    {
        return $this->command(fn () => $this->firewall()->enable());
    }

    #[OA\Put(
        path: '/firewall/disable',
        summary: 'Disable the firewall',
        description: 'Every port on the host is then open. The engine\'s own isolation of accounts and builds stays in force.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        responses: [new OA\Response(response: 200, description: 'Firewall disabled', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))],
    )]
    public function disable(): JsonResponse
    {
        return $this->command(fn () => $this->firewall()->disable());
    }

    #[OA\Put(
        path: '/firewall/reload',
        summary: 'Reload the firewall',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        responses: [new OA\Response(response: 200, description: 'Firewall reloaded', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))],
    )]
    public function reload(): JsonResponse
    {
        return $this->command(fn () => $this->firewall()->reload());
    }

    #[OA\Get(
        path: '/firewall/logs',
        summary: 'Read what the firewall blocked and banned',
        description: 'Newest first. blocked: a connection refused by the default policy (on a published port too), '
            . 'as the kernel logged it, rate-limited. ban/unban: fail2ban banning or releasing an address. '
            . 'A connection dropped by an explicit deny rule is not logged.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, minimum: 1, maximum: 1000)),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['blocked', 'ban', 'unban'])),
            new OA\Parameter(name: 'address', in: 'query', required: false, description: 'Only entries for this IPv4 or IPv6 address.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Log entries', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'time', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'type', type: 'string', enum: ['blocked', 'ban', 'unban']),
                    new OA\Property(property: 'address', type: 'string', description: 'Where a blocked connection came from (or, outbound, went to); who was banned.'),
                    new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out'], nullable: true),
                    new OA\Property(property: 'protocol', type: 'string', nullable: true),
                    new OA\Property(property: 'port', type: 'string', nullable: true),
                    new OA\Property(property: 'jail', type: 'string', nullable: true, description: 'The fail2ban jail, for a ban.'),
                ], type: 'object'))],
            )),
        ],
    )]
    public function logs(Request $request): JsonResponse
    {
        $query = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'type' => ['nullable', 'string', 'in:' . implode(',', [FirewallLogEntry::BLOCKED, FirewallLogEntry::BAN, FirewallLogEntry::UNBAN])],
            'address' => ['nullable', 'string', 'max:64', 'ip'],
        ]);
        $entries = $this->firewall()->logs((int) ($query['limit'] ?? 100), $query['type'] ?? null, $query['address'] ?? null);

        return new JsonResponse(['data' => array_map(static fn (FirewallLogEntry $e): array => $e->toArray(), $entries)]);
    }

    #[OA\Get(
        path: '/firewall/trusted',
        summary: 'List trusted addresses',
        description: 'Addresses the login protection (fail2ban) never bans: failed SSH, SFTP, FTP and engine API logins from them are not counted. '
            . 'They are not allow rules; the firewall rules still apply to them.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        responses: [
            new OA\Response(response: 200, description: 'Trusted addresses', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TrustedAddress'))],
            )),
        ],
    )]
    public function trusted(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(static fn (TrustedAddress $a): array => $a->toArray(), $this->firewall()->trustedAddresses()),
        ]);
    }

    #[OA\Post(
        path: '/firewall/trusted',
        summary: 'Trust an address',
        description: 'It is never banned again, and a ban it has now is lifted. Trust your own office, monitoring, or the panel that calls this engine, '
            . 'so a mistyped password or a stale token cannot lock it out.',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['address'],
            properties: [
                new OA\Property(property: 'address', type: 'string', example: '203.0.113.7', description: 'IPv4/IPv6 address or CIDR.'),
                new OA\Property(property: 'comment', type: 'string', nullable: true, example: 'office'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Trusted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TrustedAddress')],
            )),
        ],
    )]
    public function trust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (!is_string($value) || !FirewallRule::isAddress($value)) {
                    $fail('The address must be an IPv4 or IPv6 address or a CIDR range.');
                }
            }],
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);
        $comment = isset($data['comment']) && trim((string) $data['comment']) !== '' ? trim((string) $data['comment']) : null;
        $trusted = $this->attempt(fn () => $this->firewall()->trust(new TrustedAddress((string) $data['address'], $comment)));

        return new JsonResponse(['data' => $trusted->toArray()]);
    }

    #[OA\Delete(
        path: '/firewall/trusted/{id}',
        summary: 'Stop trusting an address',
        security: [['bearerAuth' => []]],
        tags: ['Firewall'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'No longer trusted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TrustedAddress')],
            )),
        ],
    )]
    public function untrust(string $id): JsonResponse
    {
        $removed = $this->attempt(fn () => $this->firewall()->untrust($id));

        return new JsonResponse(['data' => $removed->toArray()]);
    }

    /** The rule, when the API may change it. */
    private function changeable(string $id): FirewallRule
    {
        $rule = $this->attempt(fn () => $this->firewall()->rule($id));
        if ($rule->managed()) {
            throw ValidationException::withMessages(['id' => 'The engine opened this rule and needs it; it is not changed through the API.']);
        }
        if (!$rule->editable) {
            throw ValidationException::withMessages(['id' => 'This rule was written on the host with options the API does not carry; change it there.']);
        }

        return $rule;
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (FirewallNotFound $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (FirewallException $e) {
            throw ValidationException::withMessages(['rule' => $e->getMessage()]);
        }
    }

    private function command(callable $call): JsonResponse
    {
        try {
            $call();
        } catch (FirewallException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 502);
        }

        return new JsonResponse(['data' => '']);
    }
}
