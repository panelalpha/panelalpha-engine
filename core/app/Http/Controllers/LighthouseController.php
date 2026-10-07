<?php

namespace App\Http\Controllers;

use App\Lib\Lighthouse\LighthouseFailed;
use App\Lib\Lighthouse\LighthouseRunner;
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
                    description: 'Drop every embedded data: URI -- the final screenshot, the filmstrip '
                        . 'thumbnails, the full-page screenshot -- leaving null and a <key>StrippedBytes '
                        . 'count. They dwarf the score/metric data and cannot be rendered inline anyway.',
                    x: ['mcp-default' => '1']
                ),
                new OA\Property(
                    property: 'summary',
                    type: 'boolean',
                    nullable: true,
                    description: 'Return only the scores: the URLs, the category scores and, per audit, its '
                        . 'title, score and displayed value. No audit details.',
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
         *   summary?: ?bool,
         * } $params
         */
        $params = $request->validate([
            'url' => 'url|required',
            'desktop_preset' => 'boolean|nullable',
            'no_local_resolve' => 'boolean|nullable',
            'strip_screenshot' => 'boolean|nullable',
            'summary' => 'boolean|nullable',
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

        try {
            $result = (new LighthouseRunner(app(System::class)))
                ->report($url, $desktop, LighthouseTarget::resolverRules($pin, $engineIp));
        } catch (LighthouseFailed $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 502);
        }

        $strayed = $target->strayedTo($result);
        if ($strayed !== null) {
            return new JsonResponse([
                'message' => "The page redirected to {$strayed}, which is not a domain hosted on this engine; "
                    . 'the report is withheld.',
            ], 422);
        }

        if ($request->boolean('summary')) {
            return new JsonResponse(['data' => $this->summary($result)]);
        }

        if ($request->boolean('strip_screenshot')) {
            $this->stripScreenshotData($result);
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * Screenshots come as base64 data: URIs in several places (final-screenshot,
     * the screenshot-thumbnails filmstrip, top-level fullPageScreenshot), and
     * any of them dwarfs the scores. Each becomes null plus <key>StrippedBytes.
     *
     * @param array<array-key, mixed> $node
     */
    private function stripScreenshotData(array &$node): void
    {
        $sizes = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->stripScreenshotData($node[$key]);
            } elseif (is_string($value) && str_starts_with($value, 'data:')) {
                $node[$key] = null;
                if (is_string($key)) {
                    $sizes[$key . 'StrippedBytes'] = strlen($value);
                }
            }
        }
        $node += $sizes;
    }

    /**
     * The scores without the evidence. A full report runs to 100k+ characters
     * of request tables and traces even with the images gone; this is a few
     * hundred bytes per audit, bounded by how many audits Lighthouse ran.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function summary(array $result): array
    {
        $categories = [];
        foreach ((array)($result['categories'] ?? []) as $id => $category) {
            $categories[$id] = [
                'title' => $category['title'] ?? null,
                'score' => $category['score'] ?? null,
            ];
        }

        $audits = [];
        foreach ((array)($result['audits'] ?? []) as $id => $audit) {
            $audits[$id] = array_filter(
                array_intersect_key((array)$audit, array_flip([
                    'title', 'score', 'scoreDisplayMode', 'displayValue', 'numericValue', 'numericUnit', 'errorMessage',
                ])),
                fn (mixed $v): bool => $v !== null
            );
        }

        return array_filter([
            'requestedUrl' => $result['requestedUrl'] ?? null,
            'finalDisplayedUrl' => $result['finalDisplayedUrl'] ?? $result['finalUrl'] ?? null,
            'fetchTime' => $result['fetchTime'] ?? null,
            'lighthouseVersion' => $result['lighthouseVersion'] ?? null,
            'formFactor' => $result['configSettings']['formFactor'] ?? null,
            'runtimeError' => $result['runtimeError'] ?? null,
            'runWarnings' => $result['runWarnings'] ?? null,
            'categories' => $categories,
            'audits' => $audits,
        ], fn (mixed $v): bool => $v !== null);
    }

    private function engineIp(): ?string
    {
        $ip = Setting::get('default_ipv4');

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
