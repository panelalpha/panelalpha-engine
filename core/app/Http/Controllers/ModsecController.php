<?php

namespace App\Http\Controllers;

use App\Http\Resources\ModsecRulesetCollection;
use App\System;
use App\System\Services\Modsec;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use OpenApi\Attributes as OA;

class ModsecController extends Controller
{
    #[OA\Get(
        path: '/modsec/mode',
        summary: 'Get ModSecurity mode',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        responses: [
            new OA\Response(response: 200, description: 'ModSecurity config', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function getMode(): JsonResponse
    {
        $config = Setting::getModsecConfig();

        return new JsonResponse([
            'data' => $config,
        ]);
    }

    #[OA\Put(
        path: '/modsec/mode',
        summary: 'Set ModSecurity mode',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['mode'],
            properties: [new OA\Property(property: 'mode', type: 'string', enum: ['on', 'off', 'detection_only'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated config', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function setMode(Request $request): JsonResponse
    {
        /**
         * @var array{mode: string}
         */
        $params = $request->validate([
            'mode' => 'required|string|in:on,off,detection_only',
        ]);

        $config = Setting::setModsecConfig('mode', $params['mode']);

        $system = new System();
        $system->modsec()->rebuildConfig();

        return new JsonResponse([
            'data' => $config,
        ]);
    }

    #[OA\Get(
        path: '/modsec/rulesets',
        summary: 'List ModSecurity rulesets',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        responses: [
            new OA\Response(response: 200, description: 'Rulesets', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ModsecRuleset'))],
            )),
        ],
    )]
    public function getRulesets(): ModsecRulesetCollection
    {
        $system = new System();
        $sets = $system->modsec()->getRulesets();

        return new ModsecRulesetCollection($sets);
    }

    #[OA\Put(
        path: '/modsec/rulesets/{name}/enable',
        summary: 'Enable a ModSecurity ruleset',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        parameters: [new OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Ruleset enabled', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function enableRuleset(string $name): JsonResponse
    {
        $system = new System();
        if (!$system->modsec()->rulesetExists($name)) {
            abort(new JsonResponse([
                'message' => 'Ruleset not found',
            ], 404));
        }

        $config = Setting::getModsecConfig();
        if (!in_array($name, $config['enabled_rulesets'])) {
            $config['enabled_rulesets'][] = $name;
            Setting::setModsecConfig('enabled_rulesets', $config['enabled_rulesets']);
            $system->modsec()->rebuildConfig();
        }

        return new JsonResponse([
            'data' => null,
        ]);
    }

    #[OA\Put(
        path: '/modsec/rulesets/{name}/disable',
        summary: 'Disable a ModSecurity ruleset',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        parameters: [new OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Ruleset disabled', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ],
    )]
    public function disableRuleset(string $name): JsonResponse
    {
        $system = new System();
        if (!$system->modsec()->rulesetExists($name)) {
            abort(new JsonResponse([
                'message' => 'Ruleset not found',
            ], 404));
        }

        $config = Setting::getModsecConfig();
        if (in_array($name, $config['enabled_rulesets'])) {
            $enabled = [];
            foreach ($config['enabled_rulesets'] as $rs) {
                if ($rs === $name) {
                    continue;
                }
                $enabled[] = $rs;
            }
            Setting::setModsecConfig('enabled_rulesets', $enabled);
            $system->modsec()->rebuildConfig();
        }

        return new JsonResponse([
            'data' => null,
        ]);
    }

    #[OA\Put(
        path: '/modsec/rulesets/{name}/config-files',
        summary: 'Toggle ModSecurity ruleset config files',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        parameters: [new OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'enable', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'disable', type: 'array', items: new OA\Items(type: 'string')),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated rulesets', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ModsecRuleset'))],
            )),
        ],
    )]
    public function toggleConfigFiles(string $name, Request $request): ModsecRulesetCollection
    {
        $system = new System();
        if (!$system->modsec()->rulesetExists($name)) {
            abort(new JsonResponse([
                'message' => 'Ruleset not found',
            ], 404));
        }

        /**
         * @var array{
         *   enable?: ?array<string>,
         *   disable?: ?array<string>,
         * } $params
         */
        $params = $request->validate([
            // A file name in the ruleset's rules/ directory, nothing more: it
            // is joined to that path and renamed as root.
            'enable' => 'array',
            'enable.*' => ['string', 'regex:' . Modsec::CONFIG_FILE_NAME],
            'disable' => 'array',
            'disable.*' => ['string', 'regex:' . Modsec::CONFIG_FILE_NAME],
        ]);
        $enable = !empty($params['enable']) ? $params['enable'] : [];
        $disable = !empty($params['disable']) ? $params['disable'] : [];

        $system = new System();
        $system->modsec()->toggleConfigFiles($name, $enable, $disable);

        return $this->getRulesets();
    }

    #[OA\Get(
        path: '/modsec/audit-log/files',
        summary: 'List ModSecurity audit log files',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        responses: [
            new OA\Response(response: 200, description: 'Audit log files', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
        ],
    )]
    public function listAuditLogFiles(): JsonResponse
    {
        $system = new System();
        $files = $system->modsec()->listAuditLogFiles();

        return new JsonResponse([
            'data' => $files,
        ]);
    }

    #[OA\Get(
        path: '/modsec/audit-log/files/{filename}',
        summary: 'Download a ModSecurity audit log file',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        parameters: [new OA\Parameter(name: 'filename', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'File download', content: new OA\MediaType(mediaType: 'application/octet-stream')),
        ],
    )]
    public function downloadAuditLogFile(string $filename): BinaryFileResponse
    {
        $system = new System();
        $files = $system->modsec()->listAuditLogFiles();

        foreach ($files as $file) {
            if ($file['file'] == $filename) {
                return new BinaryFileResponse($file['path']);
            }
        }
        abort(new JsonResponse([
            'message' => 'File not found',
        ], 404));
    }

    #[OA\Get(
        path: '/modsec/audit-log/files/{filename}/tail',
        summary: 'Tail a ModSecurity audit log file',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        parameters: [new OA\Parameter(name: 'filename', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Tail of log file', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
        ],
    )]
    public function tailAuditLogFile(string $filename): JsonResponse
    {
        $system = new System();
        $files = $system->modsec()->listAuditLogFiles();

        $tailSize = 1024 * 50;

        foreach ($files as $file) {
            if ($file['file'] == $filename) {

                $file = fopen($file['path'], 'r');
                fseek($file, -$tailSize, SEEK_END);
                $data = fread($file, $tailSize);
                fclose($file);

                $logs = [];
                $lines = explode("\n", $data);
                for ($i = count($lines)-1; $i >=0; $i--) {
                    /** @var mixed */
                    $log = @json_decode($lines[$i]);
                    if ($log) {
                        /** @var mixed */
                        $logs[] = $log;
                    }
                }
                return new JsonResponse([
                    'data' => $logs,
                ]);
            }
        }
        abort(new JsonResponse([
            'message' => 'File not found',
        ], 404));
    }
}
