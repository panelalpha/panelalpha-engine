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
use App\Lib\Domains\NewDomain;
use App\Lib\Mail\MailDnsRecords;
use App\Lib\Ssl\ProjectCertificate;
use App\Lib\Ssl\SslCheck;
use App\Models\Domain;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use App\OpenApi\ApiDefinitions;
use App\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class DomainController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/domains',
        summary: 'List domains of a project',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of domains', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Domain'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
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
        summary: 'List installed SSL certificates for the domains of a project',
        security: [['bearerAuth' => []]],
        tags: ['SSL Certificates'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of SSL certs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SslCert'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
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
     * @param  string  $username
     * @param  string  $domain
     * @return SslCertificateResource
     */
    public function installedSslCert($username, $domain)
    {
        $user = $this->projectOr404($username);

        $domain = $this->domainOr404($user, $domain);
        if (! $domain->projectDomain()->hasSslCertificate()) {
            abort(new JsonResponse([
                'message' => 'SSL certificate not found',
            ], 404));
        }
        $cert = $domain->projectDomain()->getSslCertificateInfo();

        return new SslCertificateResource($cert);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/ssl-check',
        summary: 'Check the certificate a domain really serves',
        description: 'Connects to the domain over TLS, with the domain as SNI, and reads the certificate it presents: once at the address its DNS gives (public, what a visitor gets) and once at the address this project\'s webserver listens on (engine, what this host serves). status is the public verdict: trusted, self_signed, untrusted_issuer, expired, not_yet_valid, domain_mismatch or unreadable, else not_resolving, refused_address or unreachable. engine.serves_installed is false when the webserver serves something other than the installed certificate, for example after a renewal it has not reloaded.',
        security: [['bearerAuth' => []]],
        tags: ['SSL Certificates'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'What the domain serves', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SslCheck')],
            )),
            new OA\Response(response: 404, description: 'Project or domain not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function sslCheck(string $username, string $domain): JsonResponse
    {
        $user = $this->projectOr404($username);

        $model = $this->domainOr404($user, $domain);

        return new JsonResponse(['data' => (new SslCheck)->forDomain($model)]);
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
     * @param  string  $username
     * @param  string  $domain
     * @return DomainResource
     */
    public function show($username, $domain)
    {
        $user = $this->projectOr404($username);

        $domain = $this->domainOr404($user, $domain);

        return new DomainResource($domain);
    }

    #[OA\Get(
        path: '/projects/{username}/domains/{domain}/mail-dns',
        summary: 'List and check the mail DNS records of a domain',
        description: 'The SPF, DKIM, DMARC and MX records mail sent from this domain through this host needs. '
            .'With a relay sender domain set, mail is sent as that domain, and the records are its. '
            .'With check=1 each record is looked up and gets a status: ok, missing, wrong or unknown. '
            .'The engine does not sign mail; DKIM comes from a relay, whose selector dkim_selector checks.',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'check', in: 'query', required: false, description: 'Look the records up in DNS.', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'dkim_selector', in: 'query', required: false, description: 'The DKIM selector the relay signs with, to check its key.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mail DNS records', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project or domain not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function mailDns(string $username, string $domain, Request $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        $model = $this->domainOr404($user, $domain);

        $selector = $this->dkimSelector($request);
        $check = $request->boolean('check');

        return new JsonResponse([
            'data' => $this->mailDnsRecords($model, $selector, $check),
        ]);
    }

    private function dkimSelector(Request $request): ?string
    {
        /** @var array{dkim_selector?: ?string} $params */
        $params = $request->validate([
            'dkim_selector' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9_]([a-z0-9_-]*[a-z0-9])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9])?)*$/iD'],
        ]);

        return isset($params['dkim_selector']) && $params['dkim_selector'] !== '' ? strtolower($params['dkim_selector']) : null;
    }

    /**
     * The mail DNS records the domain needs for the configured relay, or, with $check, what DNS holds against them.
     *
     * @return array<string, mixed>
     */
    private function mailDnsRecords(Domain $model, ?string $selector, bool $check): array
    {
        $exim = Setting::getEximConfig();
        $relay = MailDnsRecords::relay($exim['smarthost_provider']);
        $sending = MailDnsRecords::sendingDomain($model->domain, $exim['sender_domain']);
        $ips = $relay === ''
            ? MailDnsRecords::hostAddresses(Setting::get('default_ipv4'), Setting::get('default_ipv6'), Ipv4NatMap::getLocalToPublicMap())
            : [];

        $records = MailDnsRecords::usingDns();

        return [
            'domain' => $model->domain,
            'sending_domain' => $sending,
            'relay' => $relay !== '' ? $relay : 'direct',
            'checked' => $check,
            'records' => $check
                ? $records->check($sending, $relay, $ips, $selector)
                : $records->expected($sending, $relay, $ips, $selector),
        ];
    }

    #[OA\Post(
        path: '/projects/{username}/domains',
        summary: 'Add a domain to a project',
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
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
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

        [$params['domain'], $params['aliases']] = NewDomain::withoutWww($params['domain'], $params['aliases'] ?? []);

        $this->refuseOverLimit($user, $params['type']);
        $this->refuseNameProblem($params['domain'], $params['aliases']);

        /** @var Domain */
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain' => $params['domain'],
            'type' => $params['type'],
            'details' => NewDomain::details($params['domain'], ! empty($params['no_ssl']), $params['aliases'], self::parentOf($params)),
        ]);

        try {
            $this->createOnHost($domain, $user, $params);
        } catch (\Exception $e) {
            $domain->delete();
            throw $e;
        }

        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->syncServices();

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
            new OA\Response(response: 422, description: 'Validation error, or an alias already on this engine', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @param  string  $domain
     * @return DomainResource
     */
    public function update($username, $domain, DomainUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        $domain = $this->domainOr404($user, $domain);

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

        $this->applyRedirect($domain, $params);

        if (array_key_exists('document_root', $params) && $params['document_root'] != $domain->getDocumentRoot()) {
            $domain->setDocumentRoot($params['document_root']);
        }

        if (array_key_exists('force_https_redirect', $params) && $params['force_https_redirect'] != $domain->forceHttpsRedirectEnabled()) {
            $domain->setForceHttpsRedirect($params['force_https_redirect']);
        }

        $this->replaceAliases($domain, $params);

        $domain->save();
        $domain->projectDomain()->rebuild();
        $reloaded = (new System)->reloadWebserver();

        return (new DomainResource($domain))->additional(['reload_pending' => ! $reloaded]);
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
            new OA\Response(response: 409, description: ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'The domain is the main domain, or has subdomains', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @param  string  $domain
     * @return DomainResource
     */
    public function destroy($username, $domain)
    {
        $user = $this->projectOr404($username);

        $domain = $this->domainOr404($user, $domain);

        if ($domain->type === 'main') {
            throw ValidationException::withMessages([
                'domain' => 'Cannot delete the main domain: rename it with project_update instead.',
            ]);
        }

        if ($domain->subdomains()->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'Cannot delete domain with existing subdomains',
            ]);
        }

        $domain->projectDomain()->delete();
        $domain->delete();
        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->syncServices();

        return new DomainResource($domain);
    }

    #[OA\Post(
        path: '/projects/{username}/domains/{domain}/request-ssl-cert',
        summary: "Request a Let's Encrypt certificate for a domain",
        description: 'Obtains a certificate for the domain from an ACME authority over HTTP-01 and '
            .'installs it, then re-renders the vhost so the site serves it. The challenge is served '
            .'by the running webserver, so nothing goes offline. The name must already resolve to '
            .'this host. Use install-ssl-cert instead when you already hold a certificate.',
        x: ['mcp-description' => "Gets and installs a Let's Encrypt certificate over HTTP-01, without downtime. "
            .'The domain must already resolve to this host.'],
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
                        .'by browsers but spends no production rate limit. For rehearsing a request.',
                    x: ['mcp-description' => 'Untrusted test certificate; spends no rate limit.']
                ),
                new OA\Property(
                    property: 'dry_run',
                    type: 'boolean',
                    description: 'Report what would be requested and whether the name is eligible, '
                        .'without contacting the authority. This checks only that the name is '
                        .'publicly issuable and the shared-zone rule; it does not resolve DNS, so a '
                        .'name with no A/AAAA record, or one pointing elsewhere, still reports '
                        .'ineligible_reason: null and then fails HTTP-01. Confirm the name resolves '
                        .'to this host before requesting.',
                    x: ['mcp-description' => 'Check eligibility only (issuable name, shared zone); does NOT verify DNS. A name that does not resolve to this host still reports no reason here.']
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
        if (! $model) {
            return new JsonResponse(['message' => 'Domain not found'], 404);
        }

        /** @var array{staging?: bool, dry_run?: bool} $params */
        $params = $request->validate([
            'staging' => 'boolean|nullable',
            'dry_run' => 'boolean|nullable',
        ]);
        $staging = (bool) ($params['staging'] ?? false);

        if (! empty($params['dry_run'])) {
            return new JsonResponse(['data' => ProjectCertificate::plan($model, $staging)]);
        }

        try {
            $status = ProjectCertificate::request($model, $staging);
        } catch (\Throwable $e) {
            return $this->certificateRefused($model, $e);
        }

        return new JsonResponse(['data' => $status]);
    }

    /**
     * The site keeps the certificate it had, so a failed issue is a refused request (422), not a broken
     * domain. The message is the authority's own where there is one, e.g. "DNS problem: NXDOMAIN ...".
     */
    private function certificateRefused(Domain $model, \Throwable $e): JsonResponse
    {
        return new JsonResponse([
            'message' => "Could not obtain a certificate for {$model->domain}: ".$e->getMessage(),
        ], 422);
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
                new OA\Property(property: 'key', type: 'string', description: 'PEM-encoded private key, or a `vault:<key>` from vault_secret_create (type `key`) holding it'),
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
     * @param  string  $username
     * @param  string  $domain
     * @return DomainResource
     */
    public function installSslCert($username, $domain, DomainInstallSslCertRequest $request)
    {
        $user = $this->projectOr404($username);

        $domain = $this->domainOr404($user, $domain);

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
                'cert' => 'Invalid certificate/key',
            ]);
        }

        $hosted = $domain->projectDomain();
        $hosted->putCertificate($params['cert'], $params['key'], $params['ca'] ?? '');

        $user->project()->reloadWebserver();
        (new System)->webserver()->reload();

        return new DomainResource($domain);
    }

    private function domainOr404(User $user, string $name): Domain
    {
        /** @var ?Domain */
        $domain = $user->domains()->getQuery()->where('domain', $name)->first();
        if (! $domain) {
            abort(new JsonResponse([
                'message' => 'Domain not found',
            ], 404));
        }

        return $domain;
    }

    private function refuseOverLimit(User $user, string $type): void
    {
        $limit = NewDomain::reachedLimit($user, $type);
        if ($limit !== null) {
            abort(new JsonResponse($type === 'addon'
                ? ['message' => "Addon domains limit of {$limit} reached.", 'error_type' => 'addon_domains_limit_reached']
                : ['message' => "Subdomains limit of {$limit} reached.", 'error_type' => 'subdomains_limit_reached'], 422));
        }
    }

    /**
     * @param  array<string>  $aliases
     *
     * @throws ValidationException
     */
    private function refuseNameProblem(string $name, array $aliases): void
    {
        $problem = NewDomain::nameProblem($name, $aliases);
        if ($problem !== null) {
            throw ValidationException::withMessages(match ($problem[0]) {
                NewDomain::INVALID_DOMAIN => ['domain' => 'Invalid domain name.'],
                NewDomain::DOMAIN_EXISTS => ['domain' => 'Domain already exists.'],
                NewDomain::INVALID_ALIAS => ['aliases' => 'Invalid alias domain name.'],
                NewDomain::ALIAS_EXISTS => ['aliases' => 'Domain already exists.'],
            });
        }
    }

    /** @param array{type: string, parent_domain?: ?string} $params */
    private static function parentOf(array $params): ?string
    {
        return $params['type'] === 'sub' ? ($params['parent_domain'] ?? null) : null;
    }

    /**
     * Creates an addon domain, or a subdomain of one of the project's own domains, on the host.
     *
     * @param  array{domain: string, type: string, parent_domain?: ?string}  $params
     *
     * @throws ValidationException
     */
    private function createOnHost(Domain $domain, User $user, array $params): void
    {
        switch ($params['type']) {
            case 'addon':
                $domain->projectDomain()->create();
                break;
            case 'sub':
                $this->assertParentDomain($user, $params);
                $domain->projectDomain()->create();
                break;
            default:
                throw ValidationException::withMessages([
                    'type' => 'Invalid value.',
                ]);
        }
    }

    /**
     * @param  array{domain: string, parent_domain?: ?string}  $params
     *
     * @throws ValidationException
     */
    private function assertParentDomain(User $user, array $params): void
    {
        if (empty($params['parent_domain'])) {
            throw ValidationException::withMessages([
                'parent_domain' => 'Invalid value.',
            ]);
        }
        if (! $user->domains()->getQuery()->where('domain', $params['parent_domain'])->exists()) {
            throw ValidationException::withMessages([
                'parent_domain' => 'Invalid value.',
            ]);
        }
        if (! Str::endsWith($params['domain'], $params['parent_domain'])) {
            throw ValidationException::withMessages([
                'domain' => 'Invalid value.',
            ]);
        }
    }

    /**
     * @param  array{redirect_enabled?: bool, redirect_url?: ?string}  $params
     *
     * @throws ValidationException when enabling a redirect with no URL sent or stored
     */
    private function applyRedirect(Domain $domain, array $params): void
    {
        if (array_key_exists('redirect_enabled', $params) && ! $params['redirect_enabled'] && $domain->redirectEnabled()) {
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
    }

    /**
     * A present `aliases` key is the new list, so `[]` removes them all; an absent key leaves them alone.
     *
     * @param  array{aliases?: ?array<mixed>}  $params
     *
     * @throws ValidationException
     */
    private function replaceAliases(Domain $domain, array $params): void
    {
        if (array_key_exists('aliases', $params)) {
            $domain->removeAliases();
            foreach ($params['aliases'] ?? [] as $alias) {
                $this->assertAliasAvailable($domain, $alias);
                $domain->addAlias($alias);
            }
        }
    }

    /**
     * Checks what addAlias() would refuse, tunnel hostnames included, so it is a 422 rather than a bare 500.
     *
     * @throws ValidationException
     */
    private function assertAliasAvailable(Domain $domain, mixed $alias): void
    {
        if (! is_string($alias) || ! filter_var($alias, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages([
                'aliases' => 'Invalid alias domain name.',
            ]);
        }
        if (! $domain->isAliasAvailable($alias)) {
            throw ValidationException::withMessages([
                'aliases' => "Domain alias {$alias} is not available",
            ]);
        }
    }
}
