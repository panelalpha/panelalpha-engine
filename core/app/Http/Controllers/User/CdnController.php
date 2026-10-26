<?php

namespace App\Http\Controllers\User;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CdnPurgeRequest;
use App\Http\Requests\CdnSetSecurityLevelRequest;
use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Cloudflare's cache and zone settings for one of a project's domains.
 *
 * Everything here is pure Cloudflare API work against the token the project
 * already holds, so it applies to classic and container-based projects alike;
 * nothing is read from or written to the account's files or container.
 */
class CdnController extends Controller
{
    private const SCOPE_CACHE_PURGE = 'Zone Cache Purge';

    private const SCOPE_SETTINGS_READ = 'Zone Settings Read';

    private const SCOPE_SETTINGS_EDIT = 'Zone Settings Edit';

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/cdn',
        summary: "Read a domain's Cloudflare CDN status",
        description: 'Maps the domain to the Cloudflare zone the project token can see, then reports the '
            ."zone's `security_level` and `development_mode` and whether the domain's DNS record is "
            .'proxied. Works for classic and container-based projects: it is pure Cloudflare API work. '
            .'Refuses with 422 when the project has no Cloudflare API token or the token lacks the scope, '
            .'and with 404 when Cloudflare has no zone for the domain.',
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CDN status', content: new OA\JsonContent(ref: '#/components/schemas/CdnStatus')),
            new OA\Response(response: 404, description: 'Not found, or no Cloudflare zone for the domain', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'No Cloudflare zone for shop.example.com is visible to this API token.'),
                    new OA\Property(property: 'code', type: 'string', example: 'zone_not_found'),
                ],
            )),
            new OA\Response(response: 422, description: 'Missing or unauthorised Cloudflare token', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * The zone and settings Cloudflare holds for one of the domain's hostnames.
     */
    public function show(string $username, string $domain): JsonResponse
    {
        [$user, $domainModel] = $this->resolve($username, $domain);
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $domainModel, self::SCOPE_SETTINGS_READ);

        $record = $client->findDnsRecord($zone['id'], $domainModel->domain);

        return new JsonResponse(['data' => [
            'provider' => 'cloudflare',
            'zone_id' => $zone['id'],
            'zone_name' => $zone['name'],
            'proxied' => (bool) ($record['proxied'] ?? false),
            'security_level' => $client->getSetting($zone['id'], 'security_level'),
            'development_mode' => self::isOn($client->getSetting($zone['id'], 'development_mode')),
        ]]);
    }

    #[OA\Post(
        path: '/projects/{username}/domains/{domain}/cdn/purge',
        summary: "Purge a domain's Cloudflare cache",
        description: 'Sends `everything=true` to purge the whole zone cache, or `files` with up to 30 URLs '
            .'to purge those. Works for classic and container-based projects: it is pure Cloudflare API '
            .'work. Refuses with 422 when the project has no Cloudflare API token or the token lacks '
            .'Zone Cache Purge, and with 404 when Cloudflare has no zone for the domain.',
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'everything', type: 'boolean', description: 'Purge the whole cache.', example: true),
                new OA\Property(
                    property: 'files',
                    type: 'array',
                    description: 'Up to 30 URLs to purge instead of the whole cache.',
                    items: new OA\Items(type: 'string', example: 'https://shop.example.com/style.css'),
                    maxItems: 30,
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cache purged', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'purged', type: 'boolean', example: true),
                ], type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Not found, or no Cloudflare zone for the domain', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'zone_not_found'),
                ],
            )),
            new OA\Response(response: 422, description: 'Missing or unauthorised Cloudflare token', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Purge the zone's cache, or the listed files.
     */
    public function purge(string $username, string $domain, CdnPurgeRequest $request): JsonResponse
    {
        [$user, $domainModel] = $this->resolve($username, $domain);
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $domainModel, self::SCOPE_CACHE_PURGE);

        $everything = $request->boolean('everything');
        $files = $everything ? [] : self::files($request->input('files'));

        $this->guard(self::SCOPE_CACHE_PURGE, fn () => $client->purgeCache($zone['id'], $everything, $files));

        return new JsonResponse(['data' => ['purged' => true]]);
    }

    #[OA\Put(
        path: '/projects/{username}/domains/{domain}/cdn/security-level',
        summary: "Set a domain's Cloudflare security level",
        description: 'Sets the Cloudflare zone `security_level`, including `under_attack` for the '
            .'"I am under attack" mode. Works for classic and container-based projects: it is pure '
            .'Cloudflare API work. Refuses with 422 when the project has no Cloudflare API token or the '
            .'token lacks Zone Settings Edit, and with 404 when Cloudflare has no zone for the domain.',
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['level'],
            properties: [
                new OA\Property(property: 'level', type: 'string', enum: Cloudflare::SECURITY_LEVELS, example: 'under_attack'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Security level set', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'security_level', type: 'string', enum: Cloudflare::SECURITY_LEVELS),
                ], type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Not found, or no Cloudflare zone for the domain', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'zone_not_found'),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error, missing or unauthorised Cloudflare token', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Set the zone's security level.
     */
    public function setSecurityLevel(string $username, string $domain, CdnSetSecurityLevelRequest $request): JsonResponse
    {
        [$user, $domainModel] = $this->resolve($username, $domain);
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $domainModel, self::SCOPE_SETTINGS_EDIT);

        $level = (string) $request->validated()['level'];
        $value = $this->guard(
            self::SCOPE_SETTINGS_EDIT,
            fn (): string => $client->setSetting($zone['id'], 'security_level', $level)
        );

        return new JsonResponse(['data' => ['security_level' => $value]]);
    }

    private function clientFor(User $user): Cloudflare
    {
        $token = $user->getCloudflareApiToken();
        if ($token === null) {
            throw ProblemException::one(
                'cloudflare_api_token',
                'cloudflare_token_missing',
                "Project '{$user->username}' has no Cloudflare API token. Save one on the project "
                .'before using the CDN.'
            );
        }

        return new Cloudflare($token);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function zoneFor(Cloudflare $client, Domain $domain, string $scope): array
    {
        try {
            return $client->findZoneForHostname($domain->domain);
        } catch (CloudflareException $e) {
            if ($e->isPermissionDenied()) {
                throw $this->permissionDenied($scope);
            }

            abort(new JsonResponse([
                'message' => "No Cloudflare zone for '{$domain->domain}' is visible to this API token.",
                'code' => 'zone_not_found',
            ], 404));
        }
    }

    private function guard(string $scope, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CloudflareException $e) {
            if (! $e->isPermissionDenied()) {
                throw $e;
            }

            throw $this->permissionDenied($scope);
        }
    }

    private function permissionDenied(string $scope): ProblemException
    {
        return ProblemException::one(
            'cloudflare_api_token',
            'cloudflare_permission_denied',
            "The Cloudflare API token is missing the {$scope} permission for this zone."
        );
    }

    /**
     * Cloudflare spells the zone's development mode `on` and `off`.
     */
    private static function isOn(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private static function files(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $file): bool => is_string($file) && $file !== ''));
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
        if (! $domainModel || (int) $domainModel->user_id !== (int) $user->id) {
            abort(new JsonResponse(['message' => 'Domain not found'], 404));
        }

        return [$user, $domainModel];
    }
}
