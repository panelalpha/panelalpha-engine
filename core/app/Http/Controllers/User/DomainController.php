<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\DomainInstallSslCertRequest;
use App\Http\Requests\DomainStoreRequest;
use App\Http\Requests\DomainUpdateRequest;
use App\Http\Resources\DomainCollection;
use App\Http\Resources\DomainResource;
use App\Http\Resources\SslCertificateCollection;
use App\Http\Resources\SslCertificateResource;
use App\System;
use App\Lib\Ssl\ProjectCertificate;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class DomainController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/domains',
        summary: 'List domains for a user',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of domains', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Domain'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param Request $request
     * @return DomainCollection
     */
    public function index($username, Request $request)
    {
        $user = $this->projectOr404($username);

        $domains = $user->domains;

        return new DomainCollection($domains);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/installed-ssl-certs',
        summary: 'List all installed SSL certs for user domains',
        security: [['bearerAuth' => []]],
        tags: ['SSL Certificates'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of SSL certs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SslCert'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return SslCertificateCollection
     */
    public function indexInstalledSslCerts($username)
    {
        $user = $this->projectOr404($username);

        $domains = $user->domains;
        $certs = [];
        foreach ($domains as $domain) {
            if ($domain->projectDomain()->hasSslCertificate()) {
                $certs[] = $domain->projectDomain()->getSslCertificateInfo();
            }
        }

        return new SslCertificateCollection($certs);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/installed-ssl-cert',
        summary: 'Get installed SSL cert for a domain',
        security: [['bearerAuth' => []]],
        tags: ['SSL Certificates'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SSL cert details', content: new OA\JsonContent(ref: '#/components/schemas/SslCert')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $domain
     * @return SslCertificateResource
     */
    public function installedSslCert($username, $domain)
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }
        if (!$domain->projectDomain()->hasSslCertificate()) {
            abort(new JsonResponse([
                'message' => 'SSL certificate not found',
            ], 404));
        }
        $cert = $domain->projectDomain()->getSslCertificateInfo();

        return new SslCertificateResource($cert);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}',
        summary: 'Get a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Domain details', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $domain
     * @return DomainResource
     */
    public function show($username, $domain)
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }

        return new DomainResource($domain);
    }

    #[OA\Post(
        path: '/projects/{username}/domains',
        summary: 'Add a domain to a user',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['domain', 'type'],
            properties: [
                new OA\Property(property: 'domain', type: 'string', example: 'extra.example.com'),
                new OA\Property(property: 'type', type: 'string', example: 'addon', enum: ['addon', 'subdomain']),
                new OA\Property(property: 'parent_domain', type: 'string', nullable: true),
                new OA\Property(property: 'no_ssl', type: 'boolean', example: false),
                new OA\Property(property: 'aliases', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Domain created', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param DomainStoreRequest $request
     * @return DomainResource
     */
    public function store($username, DomainStoreRequest $request)
    {
        $user = $this->projectOr404($username);

        /**
         * @var array{
         *   domain: string,
         *   parent_domain?: ?string,
         *   type: string,
         *   no_ssl?: ?bool,
         *   aliases?: ?array<string>
         * }
         */
        $params = $request->validated();

        if (Str::startsWith($params['domain'], 'www.')) {
            if (
                !array_key_exists('aliases', $params)
                || !is_array($params['aliases'])
            ) {
                $params['aliases'] = [];
            }
            if (!in_array($params['domain'], $params['aliases'])) {
                $params['aliases'][] = $params['domain'];
            }
            $params['domain'] = Str::after($params['domain'], 'www.');
        }

        switch ($params['type']) {
            case 'addon':
                $limit = $user->getAddonDomainsLimit();
                if ($limit !== null) {
                    /** @var int */
                    $count = $user->domains()->getQuery()->where('type', 'addon')->count();
                    if ($limit <= $count) {
                        abort(new JsonResponse([
                            'message' => "Addon domains limit of {$limit} reached.",
                            'error_type' => 'addon_domains_limit_reached',
                        ], 422));
                    }
                }
                break;
            case 'sub':
                $limit = $user->getSubdomainsLimit();
                if ($limit !== null) {
                    /** @var int */
                    $count = $user->domains()->getQuery()->where('type', 'sub')->count();
                    if ($limit <= $count) {
                        abort(new JsonResponse([
                            'message' => "Subdomains limit of {$limit} reached.",
                            'error_type' => 'subdomains_limit_reached',
                        ], 422));
                    }
                }
                break;
        }

        if (!filter_var($params['domain'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages([
                'domain' => 'Invalid domain name.'
            ]);
        }

        if (Domain::domainOrAliasExists($params['domain'])) {
            throw ValidationException::withMessages([
                'domain' => 'Domain already exists.'
            ]);
        }

        if (!empty($params['aliases'])) {
            foreach ($params['aliases'] as $alias) {
                if (!filter_var($alias, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    throw ValidationException::withMessages([
                        'aliases' => 'Invalid alias domain name.'
                    ]);
                }
                if (Domain::domainOrAliasExists($alias)) {
                    throw ValidationException::withMessages([
                        'aliases' => 'Domain already exists.',
                    ]);
                }
            }
        }

        /** @var Domain */
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain' => $params['domain'],
            'type' => $params['type'],
            'details' => [
                'document_root' => "/{$params['domain']}/public_html",
                'redirect_enabled' => false,
                'redirect_url' => null,
                'force_https_redirect' => false,
                'ssl_disabled' => !empty($params['no_ssl']),
                'aliases' => $params['aliases'] ?? [],
            ],
        ]);

        try {
            switch ($params['type']) {
                case 'addon':
                    $domain->projectDomain()->create();
                    break;
                case 'sub':
                    if (empty($params['parent_domain'])) {
                        throw ValidationException::withMessages([
                            'parent_domain' => 'Invalid value.',
                        ]);
                    }
                    if (!$user->domains()->getQuery()->where('domain', $params['parent_domain'])->exists()) {
                        throw ValidationException::withMessages([
                            'parent_domain' => 'Invalid value.',
                        ]);
                    }
                    if (!Str::endsWith($params['domain'], $params['parent_domain'])) {
                        throw ValidationException::withMessages([
                            'domain' => 'Invalid value.',
                        ]);
                    }
                    $domain->projectDomain()->create();
                    $domain->setDetails(['parent_domain' => $params['parent_domain']]);
                    break;
                default:
                    throw ValidationException::withMessages([
                        'type' => 'Invalid value.',
                    ]);
                    break;
            }
        } catch (\Exception $e) {
            $domain->delete();
            throw $e;
        }

        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->runEntrypointScriptsSync();

        return new DomainResource($domain);
    }

    #[OA\Put(
        path: '/projects/{username}/domains/{domain}',
        summary: 'Update a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'document_root', type: 'string'),
                new OA\Property(property: 'redirect_enabled', type: 'boolean'),
                new OA\Property(property: 'redirect_url', type: 'string', nullable: true),
                new OA\Property(property: 'force_https_redirect', type: 'boolean'),
                new OA\Property(property: 'aliases', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Domain updated', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $domain
     * @param DomainUpdateRequest $request
     * @return DomainResource
     */
    public function update($username, $domain, DomainUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }

        /**
         * @var array{
         *   document_root?: string,
         *   redirect_enabled?: bool,
         *   redirect_url?: ?string,
         *   force_https_redirect?: bool,
         *   aliases?: ?array<string>
         * }
         */
        $params = $request->validated();

        if (array_key_exists('redirect_enabled', $params) && !$params['redirect_enabled'] && $domain->redirectEnabled()) {
            $domain->disableRedirect();
        }

        if (array_key_exists('redirect_enabled', $params) && $params['redirect_enabled']) {
            $redirectUrl = $params['redirect_url'] ?? $domain->getRedirectUrl();
            if (empty($redirectUrl)) {
                throw ValidationException::withMessages([
                    'redirect_url' => 'redirect_url is required',
                ]);
            }
            if ($redirectUrl != $domain->getRedirectUrl()) {
                $domain->setRedirectUrl($redirectUrl);
            }
        }

        if (array_key_exists('document_root', $params) && $params['document_root'] != $domain->getDocumentRoot()) {
            $domain->setDocumentRoot($params['document_root']);
        }

        if (array_key_exists('force_https_redirect', $params) && $params['force_https_redirect'] != $domain->forceHttpsRedirectEnabled()) {
            $domain->setForceHttpsRedirect($params['force_https_redirect']);
        }

        if (!empty($params['aliases'])) {
            $domain->removeAliases();
            foreach ($params['aliases'] as $alias) {
                if (!filter_var($alias, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    throw ValidationException::withMessages([
                        'aliases' => 'Invalid alias domain name.'
                    ]);
                }
                if ($domain->findOtherDomainByNameOrAlias($alias)) {
                    throw ValidationException::withMessages([
                        'aliases' => "Domain alias {$alias} is not available",
                    ]);
                }
                $domain->addAlias($alias);
            }
        }

        $domain->save();
        $domain->projectDomain()->rebuild();
        $reloaded = (new System)->reloadWebserver();

        return (new DomainResource($domain))->additional(['reload_pending' => !$reloaded]);
    }

    #[OA\Delete(
        path: '/projects/{username}/domains/{domain}',
        summary: 'Delete a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Domain deleted', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $domain
     * @return DomainResource
     */
    public function destroy($username, $domain)
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }

        if ($domain->subdomains()->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'Cannot delete domain with existing subdomains',
            ]);
        }

        $domain->projectDomain()->delete();
        $domain->delete();
        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->runEntrypointScriptsSync();

        return new DomainResource($domain);
    }

    #[OA\Post(
        path: '/projects/{username}/domains/{domain}/request-ssl-cert',
        summary: "Request a Let's Encrypt certificate for a domain",
        description: "Obtains a certificate for the domain from an ACME authority over HTTP-01 and "
            . "installs it, then re-renders the vhost so the site serves it. The challenge is served "
            . "by the running webserver, so nothing goes offline. The name must already resolve to "
            . "this host. Use install-ssl-cert instead when you already hold a certificate.",
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'staging',
                    type: 'boolean',
                    description: "Use Let's Encrypt's staging authority: the certificate is untrusted "
                        . 'by browsers but spends no production rate limit. For rehearsing a request.'
                ),
                new OA\Property(
                    property: 'dry_run',
                    type: 'boolean',
                    description: 'Report what would be requested and why it would or would not work, '
                        . 'without contacting the authority.'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Certificate installed, or the plan when dry_run', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The name cannot be certified, or the authority declined', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function requestSslCert(string $username, string $domain, Request $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain $model */
        $model = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$model) {
            return new JsonResponse(['message' => 'Domain not found'], 404);
        }

        /** @var array{staging?: bool, dry_run?: bool} $params */
        $params = $request->validate([
            'staging' => 'boolean|nullable',
            'dry_run' => 'boolean|nullable',
        ]);
        $staging = (bool) ($params['staging'] ?? false);

        if (!empty($params['dry_run'])) {
            return new JsonResponse(['data' => ProjectCertificate::plan($model, $staging)]);
        }

        try {
            $status = ProjectCertificate::request($model, $staging);
        } catch (\Throwable $e) {
            // The site keeps the certificate it had, so this is a refused
            // request rather than a broken domain. The message is the
            // authority's own where there is one -- "DNS problem: NXDOMAIN
            // looking up A for ..." is the answer the caller needs.
            return new JsonResponse([
                'message' => "Could not obtain a certificate for {$model->domain}: " . $e->getMessage(),
            ], 422);
        }

        return new JsonResponse(['data' => $status]);
    }

    #[OA\Put(
        path: '/projects/{username}/domains/{domain}/install-ssl-cert',
        summary: 'Install a custom SSL certificate on a domain',
        security: [['bearerAuth' => []]],
        tags: ['SSL Certificates'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['cert', 'key'],
            properties: [
                new OA\Property(property: 'cert', type: 'string', description: 'PEM-encoded certificate'),
                new OA\Property(property: 'key', type: 'string', description: 'PEM-encoded private key'),
                new OA\Property(property: 'ca', type: 'string', description: 'PEM-encoded CA bundle', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'SSL cert installed', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid cert/key', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param string $domain
     * @param DomainInstallSslCertRequest $request
     * @return DomainResource
     */
    public function installSslCert($username, $domain, DomainInstallSslCertRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $domain)->first();
        if (!$domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }

        /**
         * @var array{
         *   cert: string,
         *   key: string,
         *   ca?: ?string
         * }
         */
        $params = $request->validated();

        if (openssl_x509_check_private_key($params['cert'], $params['key']) === false) {
            throw ValidationException::withMessages([
                'Invalid certificate/key',
            ]);
        }

        $hosted = $domain->projectDomain();
        $hosted->putCertificate($params['cert'], $params['key'], $params['ca'] ?? '');

        $user->project()->reloadWebserver();
        (new System())->webserver()->reload();

        return new DomainResource($domain);
    }
}
