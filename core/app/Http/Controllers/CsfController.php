<?php

namespace App\Http\Controllers;

use App\Http\Requests\Csf\AddRuleRequest;
use App\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * @psalm-import-type NewCsfRule from \App\System\Services\Csf
 */
class CsfController extends Controller
{
    private const PORT_RULE = 'A port rule, e.g. tcp|in|d=22|s=1.2.3.4, takes protocol, direction, port_prefix, port '
        . 'and target_prefix together, and a partial set is refused. Leave all five out for a rule on the bare target.';

    #[OA\Get(
        path: '/csf/rules',
        summary: 'List CSF firewall rules',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [
            new OA\Response(response: 200, description: 'CSF rules', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CsfRule'))],
            )),
        ],
    )]
    public function getRules(): JsonResponse
    {
        $system = new System();
        $csf = $system->csf();

        return new JsonResponse([
            'data' => $csf->listRules(),
        ]);
    }

    #[OA\Post(
        path: '/csf/rules/{type}',
        summary: 'Add a CSF firewall rule',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['allow', 'deny']))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['target'],
            properties: [
                new OA\Property(property: 'target', type: 'string', example: '192.168.1.100'),
                new OA\Property(property: 'comment', type: 'string', nullable: true),
                new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], nullable: true, description: self::PORT_RULE),
                new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out'], nullable: true),
                new OA\Property(property: 'port_prefix', type: 'string', enum: ['s=', 'd='], nullable: true, description: 'Source or destination port.'),
                new OA\Property(property: 'port', type: 'string', nullable: true, example: '22'),
                new OA\Property(property: 'target_prefix', type: 'string', enum: ['s=', 'd=', 'u='], nullable: true, description: 'Source or destination address, or a user id.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Rule added', content: new OA\JsonContent(ref: '#/components/schemas/CsfRule')),
        ],
    )]
    public function addRule(string $type, AddRuleRequest $request): JsonResponse
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw ValidationException::withMessages([
                'Invalid rule type',
            ]);
        }

        /**
         * @var array{
         *   protocol?: ?string,
         *   direction?: ?string,
         *   port_prefix?: ?string,
         *   port?: ?string,
         *   target_prefix?: ?string,
         *   target: string,
         *   comment?: ?string,
         * }
         */
        $params = $request->validated();
        foreach (['protocol', 'direction', 'port_prefix', 'port', 'target_prefix', 'comment'] as $key) {
            if (!array_key_exists($key, $params)) {
                $params[$key] = null;
            }
        }

        $system = new System();
        $csf = $system->csf();

        $rule = $csf->addRule($type, $params);

        return new JsonResponse([
            'data' => $rule,
        ]);
    }

    #[OA\Put(
        path: '/csf/rules/{type}/{lineMd5}',
        summary: 'Edit a CSF firewall rule',
        description: 'A field not sent keeps its current value; send null to clear it.',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['allow', 'deny'])),
            new OA\Parameter(name: 'lineMd5', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['target'],
            properties: [
                new OA\Property(property: 'target', type: 'string'),
                new OA\Property(property: 'comment', type: 'string', nullable: true),
                new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], nullable: true, description: self::PORT_RULE),
                new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out'], nullable: true),
                new OA\Property(property: 'port_prefix', type: 'string', enum: ['s=', 'd='], nullable: true, description: 'Source or destination port.'),
                new OA\Property(property: 'port', type: 'string', nullable: true, example: '22'),
                new OA\Property(property: 'target_prefix', type: 'string', enum: ['s=', 'd=', 'u='], nullable: true, description: 'Source or destination address, or a user id.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/CsfRule')),
        ],
    )]
    public function editRule(string $type, string $lineMd5, AddRuleRequest $request): JsonResponse
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw ValidationException::withMessages([
                'Invalid rule type',
            ]);
        }

        /**
         * @var array{
         *   protocol?: ?string,
         *   direction?: ?string,
         *   port_prefix?: ?string,
         *   port?: ?string,
         *   target_prefix?: ?string,
         *   target: string,
         *   comment?: ?string,
         * }
         */
        $params = $request->validated();

        $system = new System();
        $csf = $system->csf();

        $rule = $csf->editRule($type, $lineMd5, $params);

        return new JsonResponse([
            'data' => $rule,
        ]);
    }

    #[OA\Delete(
        path: '/csf/rules/{type}/{lineMd5}',
        summary: 'Delete a CSF firewall rule',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['allow', 'deny'])),
            new OA\Parameter(name: 'lineMd5', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rule deleted', content: new OA\JsonContent(ref: '#/components/schemas/CsfRule')),
        ],
    )]
    public function deleteRule(string $type, string $lineMd5): JsonResponse
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw ValidationException::withMessages([
                'Invalid rule type',
            ]);
        }

        $system = new System();
        $csf = $system->csf();

        $rule = $csf->deleteRule($type, $lineMd5);

        return new JsonResponse([
            'data' => $rule,
        ]);
    }

    #[OA\Get(
        path: '/csf/ui-credentials',
        summary: 'Get CSF UI credentials',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [
            new OA\Response(response: 200, description: 'CSF UI credentials', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', description: 'Whether the UI is running; it is off unless CSF_UI=1 is set in .env'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                ])],
            )),
        ],
    )]
    public function getUiCredentials(): JsonResponse
    {
        $system = new System();
        $data = [
            'enabled' => $system->csf()->uiEnabled(),
            'username' => "panelalpha",
            'password' => $system->getEnv()['CSF_UI_PASSWORD'] ?? "",
        ];
        return new JsonResponse([
            'data' => $data,
        ]);
    }

    #[OA\Get(
        path: '/csf/status',
        summary: 'Get CSF firewall status',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [
            new OA\Response(response: 200, description: 'CSF status', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function getStatus(): JsonResponse
    {
        $system = new System();
        $csf = $system->csf();

        return new JsonResponse([
            'data' => $csf->getStatus(),
        ]);
    }

    #[OA\Put(
        path: '/csf/restart',
        summary: 'Restart CSF firewall',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [
            new OA\Response(response: 200, description: 'CSF restarted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function restart(): JsonResponse
    {
        $system = new System();
        $csf = $system->csf();

        try {
            $csf->restart();
            return new JsonResponse([
                'data' => "",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    #[OA\Put(
        path: '/csf/enable',
        summary: 'Enable CSF firewall',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [new OA\Response(response: 200, description: 'CSF enabled', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))],
    )]
    public function enable(): JsonResponse
    {
        $system = new System();
        $csf = $system->csf();

        try {
            $csf->enable();
            return new JsonResponse([
                'data' => "",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    #[OA\Put(
        path: '/csf/disable',
        summary: 'Disable CSF firewall',
        description: 'csf -x does real iptables/lfd teardown and can run past a typical client timeout, '
            . 'so this returns as soon as the disable is queued rather than waiting for it to finish. '
            . 'Poll GET /csf/status for enabled:false to confirm.',
        security: [['bearerAuth' => []]],
        tags: ['CSF'],
        responses: [new OA\Response(response: 202, description: 'CSF disable queued', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))],
    )]
    public function disable(): JsonResponse
    {
        $system = new System();
        $csf = $system->csf();

        Bus::dispatchAfterResponse(function () use ($csf) {
            try {
                $csf->disable();
            } catch (\Exception $e) {
                Log::error('CSF disable failed', ['exception' => $e]);

                return;
            }

            (new System)->runProcessOnHost([
                "bash",
                "-c",
                "echo 'service docker restart' | at now"
            ]);
        });

        return new JsonResponse([
            'data' => "",
        ], 202);
    }
}
