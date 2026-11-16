<?php

namespace App\Http\Controllers;

use App\Http\Requests\System\EngineAddressUpdateRequest;
use App\Lib\Ssl\EngineAddressSettings;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The address this engine answers on and the name its certificate is issued for: what `pae configure address`
 * changes. Requesting the certificate is PUT /system/engine-certificate. A token scoped to some projects is refused,
 * as on every global route.
 */
class SystemEngineAddressController extends Controller
{
    public function __construct(private readonly EngineAddressSettings $settings) {}

    #[OA\Get(
        path: '/system/engine-address',
        summary: 'Read the engine\'s address and certificate name',
        description: 'The address every API and MCP client is handed (`url`, APP_URL), the name the engine\'s '
            .'certificate is issued for and new project sites are named under (`cert_domain`, null for the '
            .'.panelalpha.direct default), the Let\'s Encrypt contact (`cert_email`), the tunnel a project created '
            .'without one is published under (`default_tunnel`), and the certificate actually served on `url`, read '
            .'off the wire (null when nothing answered). Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Reads the address clients connect to this engine on, the name its certificate is issued for, the Let\'s Encrypt email, the default tunnel for new projects, and the certificate actually served. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The address settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EngineAddress')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    #[OA\Put(
        path: '/system/engine-address',
        summary: 'Change the engine\'s certificate name or default tunnel',
        description: 'A field left out stays as it is. `cert_domain` also names every project site created from now on; '
            .'empty or null restores the .panelalpha.direct default, and every vhost is re-rendered in the background. '
            .'Nothing is issued here: PUT /system/engine-certificate does that. Null `cert_email` or `default_tunnel` '
            .'removes that setting. `default_tunnel` none keeps new '
            .'projects off PanelAlpha Online; an explicit `tunnel` in a create request still wins. The address itself '
            .'(`url`, APP_URL) is not a setting: the engine is bound to it, and a request that sends it is refused with '
            .'422. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Changes the certificate name new project sites are named under (cert_domain; empty restores the default), the Let\'s Encrypt email, or the default tunnel for new projects (panelalpha or none). Nothing is issued: system_engine_cert_request does that. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'cert_domain', type: 'string', nullable: true, example: 'panel.example.com', description: 'A hostname that already points at this server; empty or null for the default.'),
                new OA\Property(property: 'cert_email', type: 'string', nullable: true, example: 'ops@example.com', description: 'Where Let\'s Encrypt sends expiry warnings; empty or null for none.'),
                new OA\Property(property: 'default_tunnel', type: 'string', enum: ['panelalpha', 'none'], nullable: true, example: 'panelalpha', description: 'null removes the setting, which behaves as panelalpha.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The address settings now', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EngineAddress')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(EngineAddressUpdateRequest $request): JsonResponse
    {
        $this->settings->set($request->changes());

        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->toArray()]);
    }
}
