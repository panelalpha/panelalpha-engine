<?php

namespace App\Http\Controllers;

use App\Auth\SystemProjects;
use App\Http\Requests\SystemEgressDestroyRequest;
use App\Http\Requests\SystemEgressIndexRequest;
use App\Lib\Net\Outbound\OutboundStatus;
use App\Models\OutboundEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * The default limits on the new connections projects and host builds open to the outside: what they stopped, and
 * lifting a scan block. Server-wide, so a token scoped to some projects is refused like on every other global route.
 */
class SystemEgressController extends Controller
{
    #[OA\Get(
        path: '/system/egress',
        summary: 'List what the outbound limits stopped',
        description: 'Whether the default outbound limits are in place on this server, the settings in force, and each '
            .'time they stopped a project or a host build: a scan block (more new addresses than '
            .'EGRESS_NEW_DESTINATIONS_BURST at once and EGRESS_NEW_DESTINATIONS_PER_MINUTE after it, counted apart on '
            .'tcp ports 80 and 443 against EGRESS_WEB_NEW_DESTINATIONS_BURST and _PER_MINUTE; every new outbound '
            .'connection is then refused until `blocked_until`), a flood (more than EGRESS_NEW_CONNECTIONS_PER_SECOND '
            .'new connections a second, the rest dropped), or mail sent straight to another server on port 25 '
            .'(EGRESS_BLOCK_SMTP). `owner` is the project, or for a build the project whose checkout it built. Without '
            .'`days` only the events in force are listed. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lists the projects and builds the server\'s default outbound limits stopped (a network-scan block, too many new connections, or direct SMTP), with the settings in force. Refused 403 for a project-scoped token.'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, description: 'Every event of the last N days (1-90) instead of the ones in force.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 90)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The limits and their events', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'active', type: 'boolean', description: 'Whether the limits are in place on this server.'),
                    new OA\Property(property: 'settings', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer'), description: 'The EGRESS_* settings in force; 0 turns a limit off.'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/OutboundEvent')),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '`days` is not a whole number from 1 to 90', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The limits could not be read from the server', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(SystemEgressIndexRequest $request, OutboundStatus $status): JsonResponse
    {
        $now = Carbon::now('UTC');
        try {
            $report = $status->refresh($now);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'The outbound limits could not be read: '.$e->getMessage()], 503);
        }

        $days = $request->days();
        $events = $days === null ? $status->active($now) : $status->since($now->copy()->subDays($days));
        $events = SystemProjects::forRequest()->listable($events, static fn (OutboundEvent $event): string => $event->owner);

        return new JsonResponse([
            'active' => $report->active,
            'settings' => (object) $report->settings,
            'data' => array_map(static fn (OutboundEvent $event): array => OutboundStatus::describe($event, $now), $events),
        ]);
    }

    #[OA\Delete(
        path: '/system/egress/{owner}',
        summary: 'Lift an outbound scan block',
        description: 'Lifts the scan block of a project or a build and clears its addresses\' count of new '
            .'destinations, so it starts afresh. A block lifts on its own after EGRESS_SCAN_BLOCK_MINUTES; use this '
            .'for an application that legitimately reaches many hosts, and raise the limits in the server\'s .env if '
            .'it does so all the time. `lifted` is false when the owner had no block. Refused with 403 for a token '
            .'scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lifts the outbound network-scan block of a project or build so it may open new connections again. Refused 403 for a project-scoped token. '
            .'The block goes and its addresses start counting afresh, so a block has to be raised anew to stop '
            .'them again; this cannot be undone. Ask the operator to confirm first.'],
        parameters: [
            new OA\Parameter(name: 'owner', in: 'path', required: true, description: 'The project, or the build, as GET /system/egress names it.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The block was lifted, or there was none', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'lifted', type: 'boolean'),
                    new OA\Property(property: 'addresses', type: 'array', items: new OA\Items(type: 'string'), description: 'The addresses that lost their block.'),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Not a project or build name', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The block could not be lifted on the server', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(SystemEgressDestroyRequest $request, OutboundStatus $status): JsonResponse
    {
        try {
            $addresses = $status->unblock($request->owner(), OutboundStatus::LIFTED_BY_API, Carbon::now('UTC'));
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'The block could not be lifted: '.$e->getMessage()], 503);
        }

        return new JsonResponse(['lifted' => $addresses !== [], 'addresses' => $addresses]);
    }
}
