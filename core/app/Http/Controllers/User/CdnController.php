<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\CdnPurgeRequest;
use App\Http\Requests\CdnSetSecurityLevelRequest;
use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareFailure;
use App\Lib\Cdn\CloudflareCdn;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Cloudflare's cache and zone settings for one of a project's domains.
 *
 * The work is {@see CloudflareCdn}'s, against the project's Cloudflare API
 * token; nothing is read from or written to the account's files or container.
 */
class CdnController extends Controller
{
    private const CLOUDFLARE_FAILURES = ' A token Cloudflare does not accept at all (invalid, expired or revoked) is '
        .'refused with 422 `cloudflare_token_invalid`. A token that may not list the zone is refused with 422 naming '
        .'Zone Read. When Cloudflare refuses the request for another reason, 422 `cloudflare_request_refused` carries its '
        .'message; while it rate-limits the token, 503 `cloudflare_rate_limited`; when it fails or cannot be '
        .'reached, 502 `cloudflare_unavailable`. Retry the last two later.';

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/cdn',
        summary: "Read a domain's Cloudflare CDN status",
        description: 'Maps the domain to the Cloudflare zone the project token can see, then reports the '
            ."zone's `security_level` and `development_mode` and whether the domain's DNS record is "
            .'proxied. Needs the project\'s Cloudflare API token, which only a container-based project can hold. '
            .'Refuses with 422 when the project has no Cloudflare API token, or the token lacks Zone Settings '
            .'Read or Zone DNS Read (the problem names which), '
            .'and with 404 when Cloudflare has no zone for the domain.'
            .self::CLOUDFLARE_FAILURES,
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, description: "One of the project's domains or aliases. The zone and DNS record are those of this exact name.", schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CDN status', content: new OA\JsonContent(ref: '#/components/schemas/CdnStatus')),
            new OA\Response(response: 404, description: 'Not found, or no Cloudflare zone for the domain', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'No Cloudflare zone for shop.example.com is visible to this API token.'),
                    new OA\Property(property: 'code', type: 'string', example: 'zone_not_found'),
                ],
            )),
            new OA\Response(response: 422, description: 'Missing or unauthorised Cloudflare token, or a request Cloudflare refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 502, description: 'Cloudflare failed or could not be reached', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_unavailable'),
                ],
            )),
            new OA\Response(response: 503, description: 'Cloudflare is rate-limiting the project token', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_rate_limited'),
                ],
            )),
        ],
    )]
    /**
     * The zone and settings Cloudflare holds for one of the domain's hostnames.
     */
    public function show(string $username, string $domain, CloudflareCdn $cdn): JsonResponse
    {
        [$user, $hostname] = $this->resolve($username, $domain);

        return $this->respond(fn (): array => $cdn->status($user, $hostname));
    }

    #[OA\Post(
        path: '/projects/{username}/domains/{domain}/cdn/purge',
        summary: "Purge a domain's Cloudflare cache",
        description: 'Sends `everything=true` to purge the whole zone cache, or `files` with up to 30 URLs '
            .'to purge those. Each URL must be on one of the project\'s own hostnames (its domains, their '
            .'aliases and its tunnel hostnames), or the request is refused with 422. Purging everything is '
            .'refused with 409 `cloudflare_zone_shared` while another project on this engine has a hostname '
            .'in the same zone; the project\'s own live or staging copy does not count. Needs the project\'s Cloudflare API token, which only a container-based '
            .'project can hold. Refuses with 422 when the project has no Cloudflare API token or the token lacks '
            .'Zone Cache Purge, and with 404 when Cloudflare has no zone for the domain.'
            .self::CLOUDFLARE_FAILURES,
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, description: "One of the project's domains or aliases. The zone and DNS record are those of this exact name.", schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'everything', type: 'boolean', description: 'Purge the whole cache.', example: true),
                new OA\Property(
                    property: 'files',
                    type: 'array',
                    description: 'Up to 30 URLs on the project\'s own hostnames to purge instead of the whole cache.',
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
            new OA\Response(response: 409, description: 'The zone also serves another project on this engine (problems: cloudflare_zone_shared)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'A URL outside the project\'s hostnames, a missing or unauthorised Cloudflare token, or a request Cloudflare refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 502, description: 'Cloudflare failed or could not be reached', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_unavailable'),
                ],
            )),
            new OA\Response(response: 503, description: 'Cloudflare is rate-limiting the project token', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_rate_limited'),
                ],
            )),
        ],
    )]
    /**
     * Purge the zone's cache, or the listed files.
     */
    public function purge(string $username, string $domain, CdnPurgeRequest $request, CloudflareCdn $cdn): JsonResponse
    {
        [$user, $hostname] = $this->resolve($username, $domain);

        return $this->respond(function () use ($cdn, $user, $hostname, $request): array {
            $cdn->purge($user, $hostname, $request->purgesEverything(), $request->purgeFiles());

            return ['purged' => true];
        });
    }

    #[OA\Put(
        path: '/projects/{username}/domains/{domain}/cdn/security-level',
        summary: "Set a domain's Cloudflare security level",
        description: 'Sets the Cloudflare zone `security_level`, including `under_attack` for the '
            .'"I am under attack" mode. The level applies to the whole zone, so it is refused with 409 '
            .'`cloudflare_zone_shared` while another project on this engine has a hostname in the same zone; '
            .'the project\'s own live or staging copy does not count. '
            .'Needs the project\'s Cloudflare API token, which only a '
            .'container-based project can hold. Refuses with 422 when the project has no Cloudflare API token or the '
            .'token lacks Zone Settings Edit, and with 404 when Cloudflare has no zone for the domain.'
            .self::CLOUDFLARE_FAILURES,
        security: [['bearerAuth' => []]],
        tags: ['CDN'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, description: "One of the project's domains or aliases. The zone and DNS record are those of this exact name.", schema: new OA\Schema(type: 'string')),
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
            new OA\Response(response: 409, description: 'The zone also serves another project on this engine (problems: cloudflare_zone_shared)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, missing or unauthorised Cloudflare token, or a request Cloudflare refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 502, description: 'Cloudflare failed or could not be reached', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_unavailable'),
                ],
            )),
            new OA\Response(response: 503, description: 'Cloudflare is rate-limiting the project token', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', example: 'cloudflare_rate_limited'),
                ],
            )),
        ],
    )]
    /**
     * Set the zone's security level.
     */
    public function setSecurityLevel(string $username, string $domain, CdnSetSecurityLevelRequest $request, CloudflareCdn $cdn): JsonResponse
    {
        [$user, $hostname] = $this->resolve($username, $domain);

        return $this->respond(fn (): array => [
            'security_level' => $cdn->setSecurityLevel($user, $hostname, (string) $request->validated()['level']),
        ]);
    }

    /**
     * The service's answer under `data`, or the failure it reported as `{message, code}`.
     *
     * @param  callable(): array<string, mixed>  $operation
     */
    private function respond(callable $operation): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $operation()]);
        } catch (CloudflareFailure $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->problemCode], $e->httpStatus);
        }
    }

    /**
     * The project and the hostname asked about, one of its domains or aliases, or a 404 that
     * says which was missing. Cloudflare is asked about that hostname, not the domain it aliases.
     *
     * @return array{0: User, 1: string}
     */
    private function resolve(string $username, string $domain): array
    {
        $user = $this->projectOr404($username);

        $hostname = strtolower(trim($domain));
        $domainModel = Domain::findByNameOrAlias($hostname);
        if (! $domainModel || (int) $domainModel->user_id !== (int) $user->id) {
            abort(new JsonResponse(['message' => 'Domain not found'], 404));
        }

        return [$user, $hostname];
    }
}
