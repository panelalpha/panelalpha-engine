<?php

namespace App\Http\Controllers;

use App\Http\Resources\ModsecRulesetCollection;
use App\Lib\Modsec\AuditLogFiles;
use App\System;
use App\System\Services\Modsec;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
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
                new OA\Property(property: 'enable', type: 'array', items: new OA\Items(type: 'string'), description: 'File names as config_files lists them; the .disabled suffix is optional.'),
                new OA\Property(property: 'disable', type: 'array', items: new OA\Items(type: 'string'), description: 'File names as config_files lists them; the .disabled suffix is optional.'),
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
        path: '/modsec/custom-rules',
        summary: 'Get the custom ModSecurity rules',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        responses: [
            new OA\Response(response: 200, description: 'Custom rules', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ModsecCustomRules')],
            )),
        ],
    )]
    public function getCustomRules(): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->customRulesData(new System()),
        ]);
    }

    #[OA\Put(
        path: '/modsec/custom-rules',
        summary: 'Replace the custom ModSecurity rules',
        description: 'The rules go live only after the webserver config test parses them with every enabled ruleset, '
            . 'and, when they are loaded, passes the live config with them in place; '
            . 'otherwise the call answers 422 with what the test said and the live rules stay as they were. '
            . 'On nginx the test also runs them, and refuses rules that would switch ModSecurity off or to detection-only, '
            . 'stop the rules around them or the reading of request bodies, or deny an ordinary request; '
            . 'SecRuleEngine and ctl:ruleEngine are refused '
            . 'anywhere in the rules, skip is refused, and a skipAfter must name a SecMarker after it. '
            . 'An operator ModSecurity does not know is refused too, since ModSecurity would read it as a regular expression. '
            . 'Rule ids must be in ' . Modsec::CUSTOM_ID_MIN . '-' . Modsec::CUSTOM_ID_MAX . '. '
            . 'They apply once the `custom` ruleset is enabled and the mode is not off.',
        security: [['bearerAuth' => []]],
        tags: ['ModSecurity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['rules'],
            properties: [new OA\Property(
                property: 'rules',
                type: 'string',
                nullable: true,
                description: 'The whole rule file: SecRule, SecAction, SecMarker and SecRuleRemove/Update directives. Empty clears it.',
                example: 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"',
            )],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Custom rules', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ModsecCustomRules')],
            )),
            new OA\Response(response: 422, description: 'Rules refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function setCustomRules(Request $request): JsonResponse
    {
        /** @var array{rules: ?string} $params */
        $params = $request->validate([
            'rules' => 'present|nullable|string|max:' . Modsec::CUSTOM_RULES_MAX_BYTES,
        ]);

        $system = new System();
        try {
            $system->modsec()->saveCustomRules($params['rules'] ?? '');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rules' => $e->getMessage()]);
        }

        return new JsonResponse([
            'data' => $this->customRulesData($system),
        ]);
    }

    /**
     * @return array{rules: string, enabled: bool, id_range: array{0: int, 1: int}}
     */
    private function customRulesData(System $system): array
    {
        return [
            'rules' => $system->modsec()->customRules(),
            'enabled' => $system->modsec()->customRulesEnabled(),
            'id_range' => [Modsec::CUSTOM_ID_MIN, Modsec::CUSTOM_ID_MAX],
        ];
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
        return new JsonResponse([
            'data' => (new AuditLogFiles())->list(),
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
        $path = (new AuditLogFiles())->path($filename) ?? abort(new JsonResponse([
            'message' => 'File not found',
        ], 404));

        return new BinaryFileResponse($path);
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
        $logs = (new AuditLogFiles())->tail($filename) ?? abort(new JsonResponse([
            'message' => 'File not found',
        ], 404));

        return new JsonResponse([
            'data' => $logs,
        ]);
    }
}
