<?php

namespace App\Http\Controllers;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Mcp\Tools\Api\ApiTool;
use App\Lib\Deploy\Telemetry\Telemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * File a bug about a deployed application.
 *
 * The counterpart to the install events telemetry already sends. Those are
 * measured: the engine watched a deploy happen and wrote down what it saw.
 * This one is told, and that is the whole point — the failures worth reporting
 * by hand are precisely the ones no instrumentation catches, because the engine
 * believes it did the right thing. A deploy that "succeeds" into a blank page,
 * an app serving the engine's own placeholder, a site whose front page went
 * missing while every other route works: none of them produce a failed deploy
 * report, and every one of them is one sentence for whoever is looking at it.
 *
 * **A report is always about one project.** That is what turns it from a
 * complaint into a reproduction: the engine goes and looks, and attaches what
 * it detects the application to be (`project_inspect`), what it is doing right
 * now (`app_health_check`), what its last deploy decided, and the tail of that
 * deploy's log. The reporter supplies what only they know — what went wrong and
 * what they expected — and the engine supplies the evidence, measured at the
 * moment the report is filed rather than remembered.
 *
 * Everything downstream is shared with the install events — the same spool, the
 * same `telemetry:ship` run, the same batch to the same monitoring host — so an install
 * that already reports deploys needs no new configuration, no new port and no
 * new credential to file a bug. Reports leave as `support.bug_report`.
 *
 * Telemetry being switched off is an error here, not a silent local write.
 * Nothing on the box would ever ship the report, and answering 201 to a person
 * who then waits for a reply that cannot come is worse than telling them no.
 */
class BugReportController extends Controller
{
    #[OA\Post(
        path: '/bug-reports',
        summary: 'File a bug report about a deployed application',
        description: 'Sends a bug report to PanelAlpha through the same telemetry channel the '
            . 'install events use: the report is queued in the local spool and delivered by the '
            . 'next `telemetry:ship` run, so a 201 means "accepted and queued", not "delivered". '
            . 'The response carries the exact redacted report that will be transmitted, so a '
            . 'caller can show it before or after filing. '
            . 'A report is always about one `project`, and the engine gathers the evidence itself '
            . 'rather than asking anyone to paste it: `app.inspect` is the same report '
            . 'GET /projects/{username}/inspect answers with (what the engine detects the '
            . 'application to be, what it would build, which ports it would publish, and the drift '
            . 'between that and what the last deploy froze); `app.health` is a live probe of every '
            . 'published port from inside the account container, with the checks that ask whether '
            . 'what answered is the application at all; `deploy` is what the last deploy decided; '
            . '`log_tail` is the end of its deploy log at telemetry tier 2. The health probe is the '
            . 'only part that costs seconds and touches the container — turn it off with '
            . '`attach_health: false`. '
            . 'Everything gathered is redacted before it leaves: tokens, credentials, email '
            . 'addresses, public IPs and home directory paths are masked wherever they appear, and '
            . 'any value under a key naming a secret is replaced outright. `contact` is the one '
            . 'exception and the one field that identifies a person: it is sent exactly as given, '
            . 'is never filled in automatically, and is omitted entirely when not supplied. '
            . 'Requires telemetry to be enabled (`TELEMETRY_ENABLED`, `TELEMETRY_BUG_REPORTS`) and '
            . 'a monitoring host to be configured; otherwise the call fails rather than dropping the report.',
        security: [['bearerAuth' => []]],
        tags: ['Bug Reports'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['project', 'title', 'description'],
            properties: [
                new OA\Property(
                    property: 'title',
                    type: 'string',
                    description: 'One-line headline. Reports are grouped across installs by this '
                        . 'plus `area`, so write the symptom rather than the incident: numbers, '
                        . 'paths and ids inside it are normalised away before grouping.',
                    example: 'Next.js deploys succeed but the site answers 502'
                ),
                new OA\Property(
                    property: 'description',
                    type: 'string',
                    description: 'What happened, what was expected, and how to reproduce it. '
                        . 'Redacted, and capped at 8000 bytes.',
                    example: 'Deploying a stock create-next-app repo finishes green, but the domain '
                        . 'answers 502 until the container is restarted by hand. Engine 1.0.23, '
                        . 'Debian 12, sysbox-runc'
                ),
                new OA\Property(
                    property: 'severity',
                    type: 'string',
                    enum: BugReport::SEVERITIES,
                    default: BugReport::DEFAULT_SEVERITY,
                    description: 'How badly this hurts. Anything unrecognised becomes "'
                        . BugReport::DEFAULT_SEVERITY . '".'
                ),
                new OA\Property(
                    property: 'area',
                    type: 'string',
                    description: 'Which part of the engine, as a slug. An open set — the listed '
                        . 'values are the ones worth suggesting, not the ones allowed, and '
                        . 'anything unrecognised becomes "' . BugReport::DEFAULT_AREA . '".',
                    example: 'deploy',
                    enum: BugReport::AREAS
                ),
                new OA\Property(
                    property: 'project',
                    type: 'string',
                    description: 'The application this report is about. Required — a bug report '
                        . 'carries that project\'s inspection, health probe, last deploy and log '
                        . 'tail, which is what makes it reproducible. The account name itself is '
                        . 'never transmitted: it travels as a salted hash.',
                    example: 'shop'
                ),
                new OA\Property(
                    property: 'contact',
                    type: 'string',
                    description: 'An address support can answer on. Optional, sent unredacted, and '
                        . 'the only personal data in the report — supply it only with the '
                        . 'reporter\'s knowledge.',
                    example: 'ops@example.com'
                ),
                new OA\Property(
                    property: 'attach_log',
                    type: 'boolean',
                    default: true,
                    description: 'Attach the tail of the project\'s deploy log. Ignored when the '
                        . 'install runs below telemetry tier 2.'
                ),
                new OA\Property(
                    property: 'attach_health',
                    type: 'boolean',
                    default: true,
                    description: 'Probe the application\'s published ports while filing. Costs a '
                        . 'few seconds and touches the account container; turn it off to file '
                        . 'without waiting, or for a project that is deliberately stopped.'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Report accepted and queued for delivery', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'string', example: '01JGXR8Q4M9ZK7W2N5T3V6B0AC'),
                        new OA\Property(property: 'status', type: 'string', example: 'queued'),
                        new OA\Property(property: 'queued', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'endpoint',
                            type: 'string',
                            example: 'https://monitoring.panelalpha.com/api/v1/events'
                        ),
                        new OA\Property(
                            property: 'report',
                            type: 'object',
                            description: 'The report exactly as it will be transmitted, redaction included.'
                        ),
                    ], type: 'object'),
                ],
            )),
            new OA\Response(
                response: 404,
                description: 'No project of that name on this install',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
            new OA\Response(
                response: 409,
                description: 'Telemetry or bug reporting is disabled on this install',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')
            ),
            new OA\Response(
                response: 503,
                description: 'The install cannot send reports yet: no monitoring host configured, no install id, '
                    . 'or the spool could not be written',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   project: string,
         *   title: string,
         *   description: string,
         *   severity?: ?string,
         *   area?: ?string,
         *   contact?: ?string,
         *   attach_log?: ?bool,
         *   attach_health?: ?bool
         * } $params
         */
        $params = $request->validate([
            // Required: a bug report is about one application, and the
            // inspection and health probe that make it reproducible have
            // nowhere to point without it.
            'project' => 'required|string|max:64',
            'title' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:10|max:8000',
            // Deliberately not `in:`. Both are normalised rather than rejected —
            // a report filed against an area this engine version has no name
            // for is still a report, and refusing it would lose exactly the
            // ones written by someone running a newer panel than this engine.
            'severity' => 'nullable|string|max:32',
            'area' => 'nullable|string|max:32',
            'contact' => 'nullable|string|max:190',
            'attach_log' => 'nullable|boolean',
            'attach_health' => 'nullable|boolean',
        ]);

        $result = Telemetry::captureBugReport([
            'project' => $params['project'],
            'title' => $params['title'],
            'description' => $params['description'],
            'severity' => $params['severity'] ?? null,
            'area' => $params['area'] ?? null,
            'contact' => $params['contact'] ?? null,
            'attach_log' => $params['attach_log'] ?? true,
            'attach_health' => $params['attach_health'] ?? true,
            // Which surface filed it. Self-reported and not trusted for
            // anything -- it is a label on the report, not a permission.
            'via' => $request->headers->get(ApiTool::VIA_HEADER) === ApiTool::VIA_MCP ? 'mcp' : 'api',
        ]);

        if (!$result['queued']) {
            return new JsonResponse(['message' => $result['reason']], self::statusFor($result['status']));
        }

        return new JsonResponse([
            'data' => [
                'id' => $result['id'],
                'status' => $result['status'],
                'queued' => true,
                'endpoint' => $result['endpoint'],
                'report' => $result['report'],
            ],
        ], 201);
    }

    /**
     * What each refusal is, in HTTP.
     *
     * The distinction that matters to a caller is whether trying again could
     * ever work. `no-project` is a name that does not exist here — 404, the
     * same answer every other route gives for it. `disabled` is a decision
     * somebody made on this install and no retry changes it — 409.
     * `not-ready` and `failed` are a monitoring host that has not been configured, a
     * machine that could not fingerprint itself, or a full disk: all of them
     * are "not now", so 503 and an honest sentence.
     */
    private static function statusFor(string $status): int
    {
        return match ($status) {
            'no-project' => 404,
            'disabled' => 409,
            'invalid' => 422,
            default => 503,
        };
    }
}
