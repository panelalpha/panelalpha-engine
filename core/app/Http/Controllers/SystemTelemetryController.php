<?php

namespace App\Http\Controllers;

use App\Http\Requests\System\TelemetryUpdateRequest;
use App\Lib\Deploy\Telemetry\TelemetrySettings;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * What this engine reports about itself: what `pae configure telemetry` changes. A token scoped to some projects is
 * refused, as on every global route.
 */
class SystemTelemetryController extends Controller
{
    public function __construct(private readonly TelemetrySettings $settings) {}

    #[OA\Get(
        path: '/system/telemetry',
        summary: 'Read what this engine reports about itself',
        description: 'Whether failure reports are sent, how much each carries (`tier`: metadata, repo, log), when the '
            .'deployed application\'s source is uploaded with one (`source_bundle`: off, unexplained, failed), and '
            .'whether `pae telemetry:bug-report` may file reports. `source` says where each value comes from: '
            .'`setting` (the engine database), `env` (.env-core) or `default`. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Reads whether this engine sends failure reports, how much they carry, whether application source is uploaded with them, and whether bug reports may be filed. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The telemetry settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TelemetrySettings')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    #[OA\Put(
        path: '/system/telemetry',
        summary: 'Change what this engine reports about itself',
        description: 'A field left out stays as it is. `sending` is stored as the telemetry_enabled setting, which '
            .'overrides TELEMETRY_ENABLED, and monitoring is told at once (`monitoring` in the answer says whether it '
            .'could be; the local switch stands either way). `tier`, `source_bundle` and `bug_reports` are stored in '
            .'the engine database, where they win over TELEMETRY_TIER, TELEMETRY_SOURCE_BUNDLE and '
            .'TELEMETRY_BUG_REPORTS in .env-core; null puts .env-core back in charge. They apply from the next request; '
            .'a queue worker picks them up when it next restarts, within the hour. `pae configure telemetry` saves the '
            .'same. `source_bundle` other than off uploads the customer\'s own code with a failed '
            .'deploy\'s report: ask the operator first. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Changes whether this engine sends failure reports (sending), how much they carry (tier: metadata, repo, log), when application source is uploaded with them (source_bundle: off, unexplained, failed) and whether bug reports may be filed. Uploading source sends the customer\'s code: ask the operator first. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sending', type: 'boolean', example: true, description: 'Whether reports are sent at all.'),
                new OA\Property(property: 'tier', type: 'string', nullable: true, enum: ['metadata', 'repo', 'log'], example: 'log', description: 'How much a report carries: what failed, plus the repository, plus a redacted log tail.'),
                new OA\Property(property: 'source_bundle', type: 'string', nullable: true, enum: ['off', 'unexplained', 'failed'], example: 'off', description: 'When the application\'s source is uploaded with a report.'),
                new OA\Property(property: 'bug_reports', type: 'boolean', nullable: true, example: true, description: 'Whether `pae telemetry:bug-report` may file reports.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The telemetry settings now', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/TelemetrySettings'),
                    new OA\Property(property: 'monitoring', type: 'object', nullable: true, description: 'How telling monitoring about `sending` went; null when sending did not change.', properties: [
                        new OA\Property(property: 'ok', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(TelemetryUpdateRequest $request): JsonResponse
    {
        $monitoring = $this->settings->set($request->changes());

        return $this->answer(['monitoring' => $monitoring]);
    }

    /** @param  array<string, mixed>  $extra */
    private function answer(array $extra = []): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->toArray(), ...$extra]);
    }
}
