<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TunnelCollection;
use App\Http\Resources\TunnelResource;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Public hostnames a domain can also be reached at, without touching DNS.
 *
 * The work is {@see TunnelManager}'s, exactly as it is for the
 * `domain:tunnel:*` console commands -- both call the same guard and the same
 * creator, so a rule added in one place holds for both.
 */
class TunnelController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/tunnels',
        summary: "List a domain's public tunnel hostnames",
        security: [['bearerAuth' => []]],
        tags: ['Tunnels'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of tunnels', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Tunnel'))],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * List the tunnels attached to one domain.
     */
    public function index(string $username, string $domain): TunnelCollection
    {
        [, $domainModel] = $this->resolve($username, $domain);

        return new TunnelCollection(
            Tunnel::query()->where('domain_id', $domainModel->id)->orderBy('hostname')->get()
        );
    }

    #[OA\Post(
        path: '/projects/{username}/domains/{domain}/tunnels',
        summary: 'Attach a public tunnel hostname to a domain',
        description: 'Registers a public hostname that reaches this domain without any DNS record of your own. '
            . 'The `panelalpha` provider allocates a name under panelalpha.online through the licensing proxy '
            . '-- the way to give an application a public address with a trusted certificate on an engine with '
            . 'no DNS of its own. The proxy forwards each name with itself as `Host`, and the domain\'s site '
            . 'answers to it. Attach the project\'s own name to the project domain of the same name. A project '
            . 'may also add a few sibling names beside it, such as `api-<name>.panelalpha.online`, for an '
            . 'application that routes by Host to fixed extra names; the application has to accept those names, '
            . 'so its prefixes must be configurable. Nested names such as `api.<name>.panelalpha.online` are '
            . 'not available: an application that needs them needs a domain of the customer\'s own plus addon '
            . 'domains. Labels are first come, first served across '
            . 'the fleet, so a name in use is refused; deleting the tunnel releases it again. `cloudflare` '
            . 'puts a CNAME in a zone the project\'s own '
            . 'API token controls, so it fits a custom domain; set the token first with PUT '
            . '/projects/{username}/settings/cloudflare-api-token.',
        security: [['bearerAuth' => []]],
        tags: ['Tunnels'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['hostname'],
            properties: [
                new OA\Property(
                    property: 'hostname',
                    type: 'string',
                    description: 'The public hostname to attach. For the panelalpha provider it must be a '
                        . 'single label under panelalpha.online: the name of the domain it is attached to, '
                        . 'or a sibling of it such as `api-<name>.panelalpha.online`.',
                    example: 'my-shop.panelalpha.online'
                ),
                new OA\Property(
                    property: 'provider',
                    type: 'string',
                    enum: ['cloudflare', 'panelalpha'],
                    description: 'Defaults to panelalpha.',
                    example: 'panelalpha'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Tunnel created', content: new OA\JsonContent(ref: '#/components/schemas/Tunnel')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Attach a public tunnel hostname to a domain.
     */
    public function store(string $username, string $domain, Request $request): JsonResponse
    {
        [$user, $domainModel] = $this->resolve($username, $domain);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'provider' => ['sometimes', 'string', 'in:' . implode(',', Tunnel::PROVIDERS)],
        ]);

        $hostname = (string) $validated['hostname'];
        $provider = (string) ($validated['provider'] ?? Tunnel::PROVIDER_PANELALPHA);

        try {
            // The same two calls the console command makes, in the same order.
            TunnelManager::assertCreatable($user, $domainModel, $hostname, $provider);
            $tunnel = TunnelManager::createTunnel($user, $domainModel, $hostname, $provider);
        } catch (CloudflareException | PanelAlphaException $e) {
            // A refused hostname or an unusable provider is the caller's input,
            // not a server fault.
            throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
        }

        return (new TunnelResource($tunnel))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/projects/{username}/domains/{domain}/tunnels/{hostname}',
        summary: 'Remove a public tunnel hostname',
        description: 'Cloudflare tunnels lose their DNS record and ingress rule. A PanelAlpha Online hostname '
            . 'is released here and at the proxy, so the label can be taken again. A remote delete that fails '
            . 'is logged rather than raised: the local row goes either way, and the registration then expires '
            . 'on its own.',
        security: [['bearerAuth' => []]],
        tags: ['Tunnels'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hostname', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tunnel removed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Removal failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Remove a public tunnel hostname.
     */
    public function destroy(string $username, string $domain, string $hostname): JsonResponse
    {
        [$user, $domainModel] = $this->resolve($username, $domain);

        $tunnel = Tunnel::findByHostname(strtolower(trim($hostname)));
        if (!$tunnel
            || (int) $tunnel->user_id !== (int) $user->id
            || (int) $tunnel->domain_id !== (int) $domainModel->id
        ) {
            abort(new JsonResponse(['message' => 'Tunnel not found'], 404));
        }

        try {
            TunnelManager::deleteTunnel($user, $tunnel);
        } catch (CloudflareException | PanelAlphaException $e) {
            throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * The project and one of its domains, or a 404 that says which was missing.
     *
     * @return array{0: User, 1: Domain}
     */
    private function resolve(string $username, string $domain): array
    {
        $user = $this->projectOr404($username);

        $domainModel = Domain::findByNameOrAlias(strtolower(trim($domain)));
        if (!$domainModel || (int) $domainModel->user_id !== (int) $user->id) {
            abort(new JsonResponse(['message' => 'Domain not found'], 404));
        }

        return [$user, $domainModel];
    }
}
