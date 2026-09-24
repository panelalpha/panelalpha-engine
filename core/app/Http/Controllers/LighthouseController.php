<?php

namespace App\Http\Controllers;

use App\Lib\Lighthouse\LighthouseTarget;
use App\System;
use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class LighthouseController extends Controller
{
    #[OA\Post(
        path: '/lighthouse/generate-report',
        summary: 'Generate a Lighthouse performance report',
        security: [['bearerAuth' => []]],
        tags: ['Lighthouse'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url'],
            properties: [
                new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://example.com'),
                new OA\Property(property: 'desktop_preset', type: 'boolean', nullable: true),
                new OA\Property(property: 'no_local_resolve', type: 'boolean', nullable: true),
                new OA\Property(
                    property: 'strip_screenshot',
                    type: 'boolean',
                    nullable: true,
                    description: 'Drop the final-screenshot audit\'s embedded base64 PNG (details.data), '
                        . 'which otherwise dwarfs the score/metric data in the report and cannot be '
                        . 'rendered inline anyway.',
                    x: ['mcp-default' => '1']
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Lighthouse report', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function generateReport(Request $request): JsonResponse
    {
        if (!empty(Setting::get('disable-lighthouse'))) {
            throw ValidationException::withMessages([
                'lighthouse service is disabled',
            ]);
        }

        /**
         * @var array{
         *   url: string,
         *   desktop_preset?: ?bool,
         *   no_local_resolve?: ?bool,
         *   strip_screenshot?: ?bool,
         * } $params
         */
        $params = $request->validate([
            'url' => 'url|required',
            'desktop_preset' => 'boolean|nullable',
            'no_local_resolve' => 'boolean|nullable',
            'strip_screenshot' => 'boolean|nullable',
        ]);

        // if (empty($params['url'])) {
        //     throw ValidationException::withMessages([
        //         '`url` parameter is required',
        //     ]);
        // }

        $url = $params['url'];
        $desktop = !empty($params['desktop_preset']);
        $localResolve = empty($params['no_local_resolve']);

        // Chrome fetches from inside the engine's network: only this engine's
        // own domains, and nothing private for whatever they lead to.
        $target = LighthouseTarget::forThisEngine();
        $host = $target->hostOf($url);
        $engineIp = $this->engineIp();
        $pin = $localResolve && $engineIp !== null && Domain::existsByName($host) ? $host : null;

        $system = app(System::class);
        $filename = md5($url) . ($desktop ? "-desktop" : "-mobile") . ".json";
        $path = "/data/{$filename}";
        $realPath = $system->engineDirPath() . "/data/lighthouse/{$filename}";

        $chromeFlags = [
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--ignore-certificate-errors',
        ];

        $chromeFlags[] = '--host-resolver-rules="' . LighthouseTarget::resolverRules($pin, $engineIp) . '"';

        $args = [
            "sudo",
            "docker",
            "compose",
            "-f",
            $system->composeFilePath(),
            "exec",
            "-T",
            "lighthouse",
            "lighthouse",
            $url,
            "--output",
            "json",
            "--output-path",
            $path,
            "--chrome-flags=" . escapeshellarg(implode(" ", $chromeFlags)),
            "--ignore-status-code",
            "--no-enable-error-reporting",
            "--only-audits=final-screenshot",
            "--only-categories=performance",
        ];
        if ($desktop) {
            $args[] = "--preset=desktop";
        }
        try {
            $system->exec($args);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 502);
        }

        if (!file_exists($realPath)) {
            return new JsonResponse([
                'message' => 'report file not found',
            ], 502);
        }

        $result = file_get_contents($realPath);
        $system->exec(["sudo", "rm", "-rf", $realPath]);
        if (!is_string($result)) {
            return new JsonResponse([
                'message' => 'cannot read report file',
            ], 502);
        }

        /** @var mixed */
        $result = json_decode($result, true);
        if (!is_array($result)) {
            return new JsonResponse([
                'message' => 'cannot parse report file',
            ], 502);
        }

        $strayed = $target->strayedTo($result);
        if ($strayed !== null) {
            return new JsonResponse([
                'message' => "The page redirected to {$strayed}, which is not a domain hosted on this engine; "
                    . 'the report is withheld.',
            ], 422);
        }

        if ($request->boolean('strip_screenshot')) {
            $this->stripScreenshotData($result);
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * The final-screenshot audit embeds a full base64 PNG as details.data,
     * which dwarfs the report's actual score/metric data and cannot be
     * rendered inline over a text-only transport like MCP.
     *
     * @param array<string, mixed> $result
     */
    private function stripScreenshotData(array &$result): void
    {
        foreach ((array)($result['audits'] ?? []) as $auditId => $audit) {
            $data = $audit['details']['data'] ?? null;
            if (is_string($data) && $data !== '') {
                $result['audits'][$auditId]['details']['data'] = null;
                $result['audits'][$auditId]['details']['dataStrippedBytes'] = strlen($data);
            }
        }
    }

    private function engineIp(): ?string
    {
        $ip = Setting::get('default_ipv4');

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
