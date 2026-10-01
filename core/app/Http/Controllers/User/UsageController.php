<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\BandwidthSeriesRequest;
use App\Http\Requests\VisitorsBreakdownRequest;
use App\Http\Requests\VisitorsRangeRequest;
use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectUsage;
use App\System;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class UsageController extends Controller
{
    public function __construct(private Statistics $statistics, private ProjectUsage $usage)
    {
    }

    #[OA\Get(
        path: '/projects/{username}/usage',
        description: 'Includes this calendar month\'s transfer as bandwidth.usage (bytes) against bandwidth.maximum (the project bandwidth_limit in bytes, or null when unlimited).',
        summary: 'Get resource usage for a project',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Usage statistics', content: new OA\JsonContent(ref: '#/components/schemas/Usage')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function getUsage(string $username, System $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        return new JsonResponse($this->usage->summary($user, $system));
    }

    #[OA\Get(
        path: '/projects/{username}/bandwidth',
        description: 'Values are bytes. Project transfer is the sum of that project\'s domains. Transfer is every response, robots included, so it exceeds the viewed traffic AWStats reports. Missing AWStats data is an empty object, not an error.',
        summary: 'Get bandwidth time series for a project',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'end', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
            new OA\Parameter(name: 'group_by', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['day', 'month'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Bandwidth series keyed by Y-m-d (first of the month when group_by=month)',
                content: new OA\JsonContent(
                    additionalProperties: new OA\AdditionalProperties(type: 'integer'),
                    example: ['2026-09-01' => 123456],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function getBandwidth(BandwidthSeriesRequest $request, string $username): JsonResponse
    {
        $user = $this->projectOr404($username);

        return new JsonResponse($this->usage->projectBandwidth(
            $user,
            $request->validated('start'),
            $request->validated('end'),
            $request->validated('group_by'),
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/bandwidth',
        description: 'Values are bytes. Transfer is every response, robots included, so it exceeds the viewed traffic AWStats reports. Missing AWStats data is an empty object, not an error.',
        summary: 'Get bandwidth time series for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'end', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
            new OA\Parameter(name: 'group_by', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['day', 'month'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Bandwidth series keyed by Y-m-d (first of the month when group_by=month)',
                content: new OA\JsonContent(
                    additionalProperties: new OA\AdditionalProperties(type: 'integer'),
                    example: ['2026-09-01' => 123456],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function getDomainBandwidth(BandwidthSeriesRequest $request, string $username, string $domain): JsonResponse
    {
        $user = $this->projectOr404($username);

        $owned = $this->usage->ownedDomain($user, $domain);
        if (!$owned) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }

        return new JsonResponse($this->statistics->domainBandwidth(
            $owned->domain,
            $request->validated('start'),
            $request->validated('end'),
            $request->validated('group_by'),
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/visitors',
        description: 'Unique visitors, total hits, visits by day, and session length. start/end clip DAY-backed fields (total hits, visits.records, visits.total). unique, visits_length, and all visitor breakdowns are the overlapping calendar months, not unique-in-range. Missing AWStats data is zeros, not an error. Period aliases such as last-week stay in the client.',
        summary: 'Get visitor overview for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'end', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Visitor overview', content: new OA\JsonContent(ref: '#/components/schemas/VisitorOverview')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function getDomainVisitors(VisitorsRangeRequest $request, string $username, string $domain): JsonResponse
    {
        $user = $this->projectOr404($username);

        $owned = $this->usage->ownedDomain($user, $domain);
        if (!$owned) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }

        return new JsonResponse($this->statistics->domainVisitors(
            $owned->domain,
            $request->validated('start'),
            $request->validated('end'),
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/visitors/{dimension}',
        description: 'Breakdown visits are the hits/visits from the AWStats section for every calendar month overlapping start/end; they are not clipped to the day range. Geo dimensions (countries, continents, regions) are empty until `geolocation:database update` has stored a local City MMDB. Device and device-brand are not implemented.',
        summary: 'Get a visitor breakdown for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'dimension',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['pages', 'countries', 'continents', 'regions', 'referrers', 'os', 'browsers']),
            ),
            new OA\Parameter(name: 'start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'end', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Visitor breakdown items',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/VisitorBreakdownItem')),
            ),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function getDomainVisitorBreakdown(VisitorsBreakdownRequest $request, string $username, string $domain, string $dimension): JsonResponse
    {
        $user = $this->projectOr404($username);

        $owned = $this->usage->ownedDomain($user, $domain);
        if (!$owned) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }

        return new JsonResponse($this->statistics->domainVisitorBreakdown(
            $owned->domain,
            $request->validated('dimension'),
            $request->validated('start'),
            $request->validated('end'),
        ));
    }
}
