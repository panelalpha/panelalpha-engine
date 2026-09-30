<?php

namespace App\Http\Controllers;

use App\Exceptions\DockerErrorException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Lib\Ssl\EngineCertificateRequest;
use App\Lib\Ssl\Issuers;
use App\Lib\Ssl\SharedZones;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\System;
use App\System\Network;
use App\System\Services\Webserver\Litespeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class SystemController extends Controller
{
    #[OA\Get(
        path: '/system/info',
        summary: 'Get system information',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'System info', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SystemInfo')],
            )),
        ],
    )]
    public function info(): JsonResponse
    {
        $system = new System();
        $webserver = $system->webserver();

        // The URL a client should use: APP_URL, which the certificate scripts
        // keep on the name the served certificate was issued for.
        $url = rtrim((string) config('app.url'), '/');

        $data = [
            'version' => config('system.version'),
            'url' => $url !== '' ? $url : null,
            'api_url' => $url !== '' ? $url . '/api' : null,
            'mcp_url' => $url !== '' ? $url . '/mcp' : null,
            'cert_domain' => Setting::get('cert_domain'),
            // What :2011 is actually presenting, which is not always what
            // cert_domain says: that setting records the last *domain*
            // certificate issued, and a fresh install serves one for the
            // host's address instead. Read rather than assumed, the same way
            // a project reports its own in details.ssl.
            'served_certificate' => self::servedCertificate($url),
            // Where a project lands when it is created without a domain of
            // its own: <name>.<sites_base_domain>. Reported because a caller
            // cannot build that name -- or know whether the sites on it can
            // hold a real certificate -- without it.
            'sites_base_domain' => $base = Setting::get('default_wildcard_domain'),
            'sites_certificates' => [
                'issuer' => Issuers::configured()->id(),
                'shared_zone' => $base === null || SharedZones::covers((string) $base),
                'shared_zone_issuance' => Issuers::sharedZoneIssuanceAllowed(),
            ],
            'default_ipv4' => Setting::get('default_ipv4'),
            'default_ipv6' => Setting::get('default_ipv6'),
            'ipv4_nat_mode' => Ipv4NatMap::isNatModeEnabled(),
            'ipv4_nat_maps' => Ipv4NatMap::all(),
            'webserver' => $webserver->getDetails(),
            'latest_webserver_change' => $system->getLatestChangeWebserverInfo(),
            'latest_update' => $system->getLatestUpdateInfo(),
            // Why new projects stopped getting panelalpha.online names, e.g. a
            // used-up site quota; null once a label is sold again (#79).
            'panelalpha_online' => [
                'last_error' => DomainAllocator::lastOnlineError(),
            ],
        ];

        return new JsonResponse([
            'data' => $data,
        ]);
    }

    /**
     * The certificate `:2011` presents, and whether it is right for the URL
     * clients are told to use.
     *
     * `cert_domain` names the last domain certificate the scripts issued. It
     * is not the same question: an engine that has never been given a name
     * serves a certificate for its address, and one whose domain request
     * failed serves the address certificate while `cert_domain` still holds
     * the name that was wanted. Reporting the file rather than the setting is
     * the difference between saying what was served and what was intended.
     *
     * @return array<string, mixed>|null null when there is no readable
     *         certificate on disk at all
     */
    private static function servedCertificate(string $url): ?array
    {
        $engine = new EngineCertificate();
        $path = $engine->certificatePath();

        if (!is_readable($path)) {
            return null;
        }

        $facts = CertificateFacts::fromPem((string) file_get_contents($path));
        if ($facts === null) {
            return null;
        }

        // The name a client actually connects to, which is what the
        // certificate has to cover for any of this to be worth reporting.
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return ['names' => $facts['domains']]
            + CertificateStatus::of($facts, $host);
    }

    #[OA\Get(
        path: '/system/ssl-config',
        summary: 'How certificates for project sites are obtained',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'SSL configuration', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function getSslConfig(): JsonResponse
    {
        return new JsonResponse(['data' => self::sslConfig()]);
    }

    #[OA\Put(
        path: '/system/ssl-config',
        summary: 'Set how certificates for project sites are obtained',
        description: "Only the fields present are changed. `issuer` self_signed (the default) means "
            . "the engine signs each project domain's certificate itself; acme means it obtains one "
            . "from an authority over HTTP-01 as each domain is created, which needs the domain to "
            . "resolve to this host. `sites_base_domain` is what a project without a domain of its "
            . "own is named under: <project>.<that>.",
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'issuer', type: 'string', enum: ['self_signed', 'acme'], nullable: true),
                new OA\Property(
                    property: 'sites_base_domain',
                    type: 'string',
                    nullable: true,
                    description: 'The domain new projects are named under. Empty clears it. A wildcard '
                        . 'zone the fleet shares is accepted and reported as shared_zone: certificates '
                        . "there spend an allowance every engine on it draws on."
                ),
                new OA\Property(
                    property: 'acme_directory_url',
                    type: 'string',
                    nullable: true,
                    description: "Any RFC 8555 directory URL. 'staging' and 'live' are shorthands for "
                        . "Let's Encrypt's. Empty restores the default."
                ),
                new OA\Property(property: 'acme_email', type: 'string', nullable: true, description: 'ACME account contact; empty clears it.'),
                new OA\Property(
                    property: 'shared_zone_issuance',
                    type: 'boolean',
                    nullable: true,
                    description: 'Whether to obtain certificates for names on a wildcard zone the whole '
                        . 'fleet shares (panelalpha.direct, nip.io, sslip.io). Off by default: those are '
                        . "one registered domain each for every engine, and Let's Encrypt counts 50 new "
                        . 'certificates per registered domain per week across all of them. On, a project '
                        . 'on the default name gets a real certificate like any other; off, it keeps the '
                        . 'self-signed one and the reason is logged. Domains a customer owns are never '
                        . 'affected either way.'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated configuration', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateSslConfig(Request $request): JsonResponse
    {
        /** @var array{issuer?: ?string, sites_base_domain?: ?string, acme_directory_url?: ?string, acme_email?: ?string} $params */
        $params = $request->validate([
            'issuer' => 'string|nullable|in:self_signed,acme',
            'sites_base_domain' => 'string|nullable|max:253',
            'acme_directory_url' => 'string|nullable|max:2048',
            'acme_email' => 'email|nullable|max:255',
            'shared_zone_issuance' => 'boolean|nullable',
        ]);

        if (array_key_exists('sites_base_domain', $params)) {
            $base = strtolower(trim((string) $params['sites_base_domain'], " \t\n\r\0\x0B."));
            if ($base !== '' && !SharedZones::isPubliclyIssuable($base)) {
                throw ValidationException::withMessages([
                    'sites_base_domain' => "{$base} is not a hostname sites can be named under.",
                ]);
            }
            Setting::set('default_wildcard_domain', $base);
        }

        foreach (['issuer' => 'ssl_issuer', 'acme_directory_url' => 'acme_directory_url', 'acme_email' => 'acme_email'] as $field => $setting) {
            if (array_key_exists($field, $params)) {
                Setting::set($setting, trim((string) $params[$field]));
            }
        }

        if (array_key_exists('shared_zone_issuance', $params)) {
            Setting::set('ssl_shared_zone_issuance', $params['shared_zone_issuance'] ? '1' : '0');
        }

        return new JsonResponse(['data' => self::sslConfig()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sslConfig(): array
    {
        $base = Setting::get('default_wildcard_domain');

        return [
            'issuer' => Issuers::configured()->id(),
            'sites_base_domain' => $base !== null && $base !== '' ? $base : null,
            // True while the sites sit on a zone every engine issues under.
            // Not a refusal -- a cost, and the operator's to weigh.
            'shared_zone' => $base === null || $base === '' || SharedZones::covers((string) $base),
            // Whether this engine will actually issue on such a zone. Off by
            // default: spending an allowance the whole fleet draws on is a
            // decision, not a consequence of turning `issuer` on.
            'shared_zone_issuance' => Issuers::sharedZoneIssuanceAllowed(),
            'acme_directory_url' => Issuers::directoryUrl(),
            'acme_email' => Issuers::email(),
        ];
    }

    #[OA\Put(
        path: '/system/engine-certificate',
        summary: "Request the engine's own Let's Encrypt certificate",
        description: "Obtains the certificate :2011 serves, for `domain` or the cert_domain setting. "
            . "**This briefly takes every hosted site offline**: certbot needs the host's :80 and "
            . "sites-http holds it, so the webserver is stopped for the length of the challenge and "
            . "brought back after — usually seconds. It is not the mechanism project certificates "
            . "use. Blocks until the request finishes, up to 15 minutes. Use dry_run first: it runs "
            . "the full challenge against staging and writes nothing.",
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'domain', type: 'string', nullable: true, description: 'The name to certify; default: the cert_domain setting.'),
                new OA\Property(property: 'email', type: 'string', nullable: true, description: 'ACME account email; default: the cert_email setting.'),
                new OA\Property(property: 'ip', type: 'string', nullable: true, description: 'The public address, when detection gets it wrong.'),
                new OA\Property(property: 'staging', type: 'boolean', nullable: true, description: "Let's Encrypt staging: untrusted, spends no rate limit."),
                new OA\Property(property: 'force_renewal', type: 'boolean', nullable: true),
                new OA\Property(property: 'skip_dns_check', type: 'boolean', nullable: true, description: 'Do not verify the name resolves to this host first.'),
                new OA\Property(property: 'dry_run', type: 'boolean', nullable: true, description: 'Full challenge, no certificate, nothing installed.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Requested; the served certificate is reported back', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'The certificate was not issued; the served one is unchanged', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function requestEngineCertificate(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $params */
        $params = $request->validate([
            'domain' => 'string|nullable|max:253',
            'email' => 'email|nullable|max:255',
            'ip' => 'ip|nullable',
            'staging' => 'boolean|nullable',
            'force_renewal' => 'boolean|nullable',
            'skip_dns_check' => 'boolean|nullable',
            'dry_run' => 'boolean|nullable',
        ]);

        $flags = [];
        foreach (['staging' => 'staging', 'force_renewal' => 'force-renewal', 'skip_dns_check' => 'skip-dns-check', 'dry_run' => 'dry-run'] as $field => $flag) {
            if (!empty($params[$field])) {
                $flags[] = $flag;
            }
        }

        $result = EngineCertificateRequest::run(
            new System(),
            [
                'domain' => isset($params['domain']) ? (string) $params['domain'] : null,
                'email' => isset($params['email']) ? (string) $params['email'] : null,
                'ip' => isset($params['ip']) ? (string) $params['ip'] : null,
            ],
            $flags
        );

        $url = rtrim((string) config('app.url'), '/');

        if (!$result['successful']) {
            throw ValidationException::withMessages([
                'domain' => 'The certificate was not issued; the served certificate is unchanged. '
                    . $result['output'],
            ]);
        }

        return new JsonResponse([
            'data' => [
                'cert_domain' => $result['cert_domain'],
                'url' => $url !== '' ? $url : null,
                // Read off the file rather than assumed: on a dry run nothing
                // was installed, and this is what says so.
                'served_certificate' => self::servedCertificate($url),
                'output' => $result['output'],
            ],
        ]);
    }

    #[OA\Put(
        path: '/system/update',
        summary: 'Trigger a system update',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'license_key', type: 'string', nullable: true)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Update initiated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   license_key?: ?string,
         * }
         */
        $params = $request->validate([
            'license_key' => 'nullable|string',
        ]);
        $licenseKey = null;
        if (!empty($params['license_key'])) {
            $licenseKey = $params['license_key'];
        }

        $system = new System();

        if ($system->isUpdateScriptRunning()) {
            throw ValidationException::withMessages([
                'Update script is already running',
            ]);
        }

        $system->runUpdateScript($licenseKey);

        $currentUpdate = $system->getLatestUpdateInfo();

        return new JsonResponse([
            'data' => $currentUpdate,
        ]);
    }

    #[OA\Put(
        path: '/system/change-webserver',
        summary: 'Change the active webserver',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['new_webserver'],
            properties: [new OA\Property(property: 'new_webserver', type: 'string', enum: ['nginx', 'nginx-proxy', 'apache', 'litespeed', 'openlitespeed'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Webserver change initiated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function changeWebserver(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   new_webserver: string,
         *   serial_number?: ?string,
         * }
         */
        $params = $request->validate([
            'new_webserver' => 'required|string',
            'serial_number' => 'nullable|string',
        ]);

        if (!in_array($params['new_webserver'], [
            'nginx',
            'nginx-proxy',
            'apache',
            'litespeed',
            'openlitespeed',
        ])) {
            throw ValidationException::withMessages([
                'new_webserver' => 'Invalid value',
            ]);
        }

        // Temporary: only nginx-proxy works correctly with DinD projects.
        if ($params['new_webserver'] !== 'nginx-proxy') {
            throw ValidationException::withMessages([
                'new_webserver' => 'Switching the webserver away from nginx-proxy is temporarily disabled. Only nginx-proxy is supported right now (required for DinD projects).',
            ]);
        }

        $serialNumber = null;
        if (!empty($params['serial_number']) && trim($params['serial_number']) !== '') {
            $serialNumber = trim($params['serial_number']);
        }

        if ($serialNumber !== null && $params['new_webserver'] !== 'litespeed') {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial number is only supported when switching to LiteSpeed',
            ]);
        }

        $system = new System();

        if ($system->isChangeWebserverScriptRunning()) {
            throw ValidationException::withMessages([
                'Change webserver script is already running',
            ]);
        }

        if ($serialNumber !== null) {
            (new Litespeed($system))->validateSerialNumberForConfig($serialNumber);
        }

        $system->runChangeWebserverScript($params['new_webserver'], $serialNumber);

        $currentUpdate = $system->getLatestChangeWebserverInfo();

        return new JsonResponse([
            'data' => $currentUpdate,
        ]);
    }

    #[OA\Put(
        path: '/system/reset-webserver-panel-password',
        summary: 'Reset the webserver panel password',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'New password', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'new_password', type: 'string'),
                ])],
            )),
        ],
    )]
    public function resetWebserverPanelPassword(): JsonResponse
    {
        $system = new System();
        $webserver = $system->webserver();

        try {
            $newPassword = $webserver->resetWebPanelPassword();
        } catch (DockerErrorException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 502);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'data' => [
                'new_password' => $newPassword,
            ],
        ]);
    }

    #[OA\Put(
        path: '/system/webserver-config',
        summary: 'Update webserver configuration',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['serial_number'],
            properties: [new OA\Property(property: 'serial_number', type: 'string')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Config updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function updateWebserverConfig(Request $request): JsonResponse
    {
        /**
         * @var array{serial_number: string}
         */
        $params = $request->validate([
            'serial_number' => 'required|string',
        ]);

        $system = new System();
        $webserver = $system->webserver();

        try {
            foreach ($params as $name => $value) {
                $webserver->updateConfig($name, $value);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (DockerErrorException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 502);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'data' => $params,
        ]);
    }

    #[OA\Get(
        path: '/system/exim-config',
        summary: 'Get Exim mail server configuration',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Exim config', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function getEximConfig(): JsonResponse
    {
        return new JsonResponse([
            'data' => Setting::getEximConfig(),
        ]);
    }

    #[OA\Put(
        path: '/system/exim-config',
        summary: 'Update Exim mail server configuration',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'smarthost_provider', type: 'string', nullable: true),
                new OA\Property(property: 'sendgrid_api_token', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_host', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_port', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_username', type: 'string', nullable: true),
                new OA\Property(property: 'sender_domain', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Config updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function updateEximConfig(Request $request): JsonResponse
    {
        $params = $request->validate([
            'smarthost_provider' => 'string|nullable',
            'sendgrid_api_token' => 'string|nullable',
            'mailchannels_username' => 'string|nullable',
            'mailchannels_password' => 'string|nullable',
            'amazon_ses_smtp_endpoint' => 'string|nullable',
            'amazon_ses_starttls_port' => 'string|nullable',
            'amazon_ses_smtp_username' => 'string|nullable',
            'amazon_ses_smtp_password' => 'string|nullable',
            'smtp_host' => 'string|nullable',
            'smtp_port' => 'string|nullable',
            'smtp_username' => 'string|nullable',
            'smtp_password' => 'string|nullable',
            'smtp_implicit_tls' => 'boolean|nullable',
            'sender_domain' => 'string|nullable',
        ]);

        $system = new System();
        Setting::updateEximConfig($params);
        $system->exim()->rebuildEximConfig();

        return new JsonResponse([
            'data' => $params,
        ]);
    }

    #[OA\Post(
        path: '/system/exim-send-test-email',
        summary: 'Send a test email via Exim',
        description: 'exit_code 0 only means Exim accepted the message. status says what happened to it: delivered, deferred (still queued; Exim retries it), failed (bounced), not_sent (Exim did not take it) or unknown; reason is the Exim log line for a deferral or a bounce.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'config', type: 'object', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Test email result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function sendTestEmail(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   email: string,
         *   config?: array
         * } $params
         */
        $params = $request->validate([
            // Goes into the message's To: header; a line break there adds headers.
            'email' => 'required|string|email|max:254',
            'config.smarthost_provider' => 'string|nullable',
            'config.sendgrid_api_token' => 'string|nullable',
            'config.mailchannels_username' => 'string|nullable',
            'config.mailchannels_password' => 'string|nullable',
            'config.amazon_ses_smtp_endpoint' => 'string|nullable',
            'config.amazon_ses_starttls_port' => 'string|nullable',
            'config.amazon_ses_smtp_username' => 'string|nullable',
            'config.amazon_ses_smtp_password' => 'string|nullable',
            'config.smtp_host' => 'string|nullable',
            'config.smtp_port' => 'string|nullable',
            'config.smtp_username' => 'string|nullable',
            'config.smtp_password' => 'string|nullable',
            'config.smtp_implicit_tls' => 'boolean|nullable',
            'config.sender_domain' => 'string|nullable',
        ]);

        $system = new System();
        // `config` is documented nullable and only `email` is required, so a
        // caller that just wants to send a test email through the settings
        // already stored must not be met with an undefined-key error.
        if (is_array($params['config'] ?? null)) {
            Setting::updateEximConfig($params['config']);
            $system->exim()->rebuildEximConfig();
        }

        $result = $system->exim()->sendTestEmail($params['email']);

        return new JsonResponse([
            'data' => $result
        ]);
    }

    public function updateNetworkConfig(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   default_ipv4?: ?string,
         *   default_ipv6?: ?string,
         * }
         */
        $params = $request->validate([
            'default_ipv4' => 'nullable|ip|ipv4',
            'default_ipv6' => 'nullable|ip|ipv6',
        ]);

        $system = new System();

        if (!empty($params['default_ipv4'])) {
            Setting::set('default_ipv4', $params['default_ipv4']);
        }
        if (array_key_exists('default_ipv6', $params)) {
            Setting::set('default_ipv6', $params['default_ipv6'] ?? '');
        }

        $reloaded = $system->rebuildDomains();

        $response = $this->info();
        return $response->setData([...(array) $response->getData(true), 'reload_pending' => !$reloaded]);
    }

    public function listIpv4NatMaps(): JsonResponse
    {
        return new JsonResponse([
            'data' => Ipv4NatMap::all(),
        ]);
    }

    public function upsertIpv4NatMap(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   local_ip: string,
         *   public_ip: string,
         * }
         */
        $params = $request->validate([
            'local_ip' => 'required|ip|ipv4',
            'public_ip' => 'required|ip|ipv4',
        ]);

        if ($params['local_ip'] === $params['public_ip']) {
            throw ValidationException::withMessages([
                'local_ip' => 'Local IP and public IP must be different.',
            ]);
        }

        $map = Ipv4NatMap::upsertMap($params['local_ip'], $params['public_ip']);

        $system = new System();
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $map,
            'reload_pending' => !$reloaded,
        ]);
    }

    public function deleteIpv4NatMap(int $id): JsonResponse
    {
        $map = Ipv4NatMap::findOrFail($id);
        $map->delete();

        $system = new System();
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $map,
            'reload_pending' => !$reloaded,
        ]);
    }

    public function rebuildIpv4NatMaps(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   lookup_url?: ?string,
         *   replace_default_ipv4?: ?bool,
         * }
         */
        $params = $request->validate([
            'lookup_url' => 'nullable|string|url',
            'replace_default_ipv4' => 'nullable|boolean',
        ]);

        $lookupUrl = $params['lookup_url'] ?? Network::DEFAULT_IPV4_NAT_LOOKUP_URL;
        $replaceDefaultIpv4 = !empty($params['replace_default_ipv4']);

        $result = (new System())->network()->rebuildIpv4NatMaps($lookupUrl, $replaceDefaultIpv4);

        $system = new System();
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $result,
            'reload_pending' => !$reloaded,
        ]);
    }
}
