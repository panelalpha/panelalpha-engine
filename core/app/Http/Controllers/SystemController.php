<?php

namespace App\Http\Controllers;

use App\Auth\TokenAbilities;
use App\Exceptions\DockerErrorException;
use App\Http\Requests\EximConfigUpdateRequest;
use App\Http\Requests\EximTestEmailSendRequest;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Mail\EximConfigSecrets;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Lib\Ssl\EngineCertificateRequest;
use App\Lib\Ssl\Issuers;
use App\Lib\Ssl\SharedZones;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\AccountContainers;
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
        $system = new System;
        $webserver = $system->webserver();
        $url = self::engineUrl();
        $caller = TokenAbilities::forCurrentRequest();

        $data = [
            'version' => config('system.version'),
            'url' => $url !== '' ? $url : null,
            'api_url' => $url !== '' ? $url.'/api' : null,
            'mcp_url' => $url !== '' ? $url.'/mcp' : null,
            'cert_domain' => Setting::get('cert_domain'),
            'default_tunnel' => self::defaultTunnel(),
            'served_certificate' => self::servedCertificate($url),
            ...self::sitesDomain(),
            'default_ipv4' => Setting::get('default_ipv4'),
            'host' => HostFacts::hostFor($caller, $system),
            'default_ipv6' => Setting::get('default_ipv6'),
            'ipv4_nat_mode' => Ipv4NatMap::isNatModeEnabled(),
            'ipv4_nat_maps' => Ipv4NatMap::all(),
            'webserver' => $webserver->getDetails(),
            'latest_webserver_change' => $system->getLatestChangeWebserverInfo(),
            'latest_update' => $system->getLatestUpdateInfo(),
            'panelalpha_online' => self::panelalphaOnline(),
            'unreadable_secrets' => self::unreadableSecrets($caller, User::query()->cursor()),
            'orphan_account_containers' => self::orphanAccountContainers($system, $caller),
        ];

        return new JsonResponse([
            'data' => $data,
        ]);
    }

    /** The configured default_tunnel, raw; null while unset. Only `none` changes the ladder. */
    public static function defaultTunnel(): ?string
    {
        return Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL);
    }

    /** The URL a client should use: APP_URL, which the certificate scripts keep on the name the served certificate was issued for. */
    private static function engineUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Where a project created without a domain of its own lands (<name>.<sites_base_domain>), and whether
     * sites there can hold a real certificate: a caller cannot know either without it.
     *
     * @return array{sites_base_domain: mixed, sites_certificates: array{issuer: string, shared_zone: bool, shared_zone_issuance: bool}}
     */
    private static function sitesDomain(): array
    {
        $base = Setting::get('default_wildcard_domain');

        return [
            'sites_base_domain' => $base,
            'sites_certificates' => [
                'issuer' => Issuers::configured()->id(),
                'shared_zone' => $base === null || SharedZones::covers((string) $base),
                'shared_zone_issuance' => Issuers::sharedZoneIssuanceAllowed(),
            ],
        ];
    }

    /**
     * Why new projects stopped getting panelalpha.online names, e.g. a used-up site quota; null once a label is sold again.
     *
     * @return array{last_error: ?array<string, mixed>}
     */
    private static function panelalphaOnline(): array
    {
        return [
            'last_error' => DomainAllocator::lastOnlineError(),
        ];
    }

    /**
     * Account containers no project owns; a token scoped to projects sees only
     * those of the projects in its scope. Reported only: the operator removes
     * them, the engine never does.
     *
     * @return array{count: int, containers: list<array<string, string>>, warning: ?string}
     */
    private static function orphanAccountContainers(System $system, TokenAbilities $caller): array
    {
        return (new AccountContainers($system))
            ->orphanReport(User::query()->pluck('username'), $caller->projects());
    }

    /**
     * The one place that names the APP_KEY: a project only knows it cannot
     * decode its secrets, the server knows that many projects at once point
     * at the key. A token scoped to projects sees only the projects in its scope.
     *
     * @param  iterable<User>  $users
     * @return array{count: int, projects: list<string>, warning: ?string}
     */
    public static function unreadableSecrets(TokenAbilities $caller, iterable $users): array
    {
        $projects = [];
        foreach ($users as $user) {
            if ($caller->mayReachProject((string) $user->username) && $user->unreadableSecrets() !== []) {
                $projects[] = (string) $user->username;
            }
        }

        $warning = $projects === [] ? null : count($projects).' project(s) hold secrets the engine cannot '
            .'decode: '.implode(', ', $projects).'. The APP_KEY may be invalid, or changed since they were '
            .'stored. Restore the previous APP_KEY before those projects are saved again, or their secrets are '
            .'stored empty.';

        return ['count' => count($projects), 'projects' => $projects, 'warning' => $warning];
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
     * the difference between saying what was served and what was intended, and
     * after a dry run it is what says nothing was installed. The status is
     * judged against the host clients connect to.
     *
     * @return array<string, mixed>|null null when there is no readable
     *                                   certificate on disk at all
     */
    private static function servedCertificate(string $url): ?array
    {
        $pem = (new EngineCertificate)->served();
        if ($pem === null) {
            return null;
        }

        $facts = CertificateFacts::fromPem($pem);
        if ($facts === null) {
            return null;
        }

        $connectedHost = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return ['names' => $facts['domains']]
            + CertificateStatus::of($facts, $connectedHost);
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
        description: 'Only the fields present are changed. `issuer` self_signed (the default) means '
            ."the engine signs each project domain's certificate itself; acme means it obtains one "
            .'from an authority over HTTP-01 as each domain is created, which needs the domain to '
            .'resolve to this host. `sites_base_domain` is what a project without a domain of its '
            .'own is named under: <project>.<that>.',
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
                        .'zone the fleet shares is accepted and reported as shared_zone: certificates '
                        .'there spend an allowance every engine on it draws on.'
                ),
                new OA\Property(
                    property: 'acme_directory_url',
                    type: 'string',
                    nullable: true,
                    description: "Any RFC 8555 directory URL. 'staging' and 'live' are shorthands for "
                        ."Let's Encrypt's. Empty restores the default."
                ),
                new OA\Property(property: 'acme_email', type: 'string', nullable: true, description: 'ACME account contact; empty clears it.'),
                new OA\Property(
                    property: 'shared_zone_issuance',
                    type: 'boolean',
                    nullable: true,
                    description: 'Whether to obtain certificates for names on a wildcard zone the whole '
                        .'fleet shares (panelalpha.direct, nip.io, sslip.io). Off by default: those are '
                        ."one registered domain each for every engine, and Let's Encrypt counts 50 new "
                        .'certificates per registered domain per week across all of them. On, a project '
                        .'on the default name gets a real certificate like any other; off, it keeps the '
                        .'self-signed one and the reason is logged. Domains a customer owns are never '
                        .'affected either way.'
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
            self::saveSitesBaseDomain((string) $params['sites_base_domain']);
        }

        foreach (['issuer' => 'ssl_issuer', 'acme_directory_url' => 'acme_directory_url', 'acme_email' => 'acme_email'] as $field => $setting) {
            if (array_key_exists($field, $params)) {
                self::setOrForget($setting, trim((string) $params[$field]));
            }
        }

        if (array_key_exists('shared_zone_issuance', $params)) {
            Setting::set('ssl_shared_zone_issuance', $params['shared_zone_issuance'] ? '1' : '0');
        }

        return new JsonResponse(['data' => self::sslConfig()]);
    }

    /** @throws ValidationException when the name cannot carry publicly issued certificates */
    private static function saveSitesBaseDomain(string $requested): void
    {
        $base = strtolower(trim($requested, " \t\n\r\0\x0B."));
        if ($base !== '' && ! SharedZones::isPubliclyIssuable($base)) {
            throw ValidationException::withMessages([
                'sites_base_domain' => "{$base} is not a hostname sites can be named under.",
            ]);
        }
        self::setOrForget('default_wildcard_domain', $base);
    }

    /** An emptied value removes the setting, so it reads back as null rather than "". */
    private static function setOrForget(string $setting, string $value): void
    {
        if ($value === '') {
            Setting::forget($setting);

            return;
        }
        Setting::set($setting, $value);
    }

    /**
     * `shared_zone` is true while the sites sit on a zone every engine issues under: a cost for the
     * operator to weigh, not a refusal. `shared_zone_issuance` says whether this engine will issue
     * there; it is off by default, since spending an allowance the whole fleet draws on is a decision.
     *
     * @return array<string, mixed>
     */
    private static function sslConfig(): array
    {
        $base = Setting::get('default_wildcard_domain');

        return [
            'issuer' => Issuers::configured()->id(),
            'sites_base_domain' => $base !== null && $base !== '' ? $base : null,
            'shared_zone' => $base === null || $base === '' || SharedZones::covers((string) $base),
            'shared_zone_issuance' => Issuers::sharedZoneIssuanceAllowed(),
            'acme_directory_url' => Issuers::directoryUrl(),
            'acme_email' => Issuers::email(),
        ];
    }

    #[OA\Put(
        path: '/system/engine-certificate',
        summary: "Request the engine's own Let's Encrypt certificate",
        description: 'Obtains the certificate :2011 serves, for `domain` or the cert_domain setting. '
            ."**This briefly takes every hosted site offline**: certbot needs the host's :80 and "
            .'sites-http holds it, so the webserver is stopped for the length of the challenge and '
            .'brought back after — usually seconds. It is not the mechanism project certificates '
            .'use. Blocks until the request finishes, up to 15 minutes. Use dry_run first: it runs '
            .'the full challenge against staging and writes nothing.',
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

        $result = EngineCertificateRequest::run(
            new System,
            [
                'domain' => isset($params['domain']) ? (string) $params['domain'] : null,
                'email' => isset($params['email']) ? (string) $params['email'] : null,
                'ip' => isset($params['ip']) ? (string) $params['ip'] : null,
            ],
            self::certificateFlags($params)
        );

        $url = self::engineUrl();

        if (! $result['successful']) {
            throw ValidationException::withMessages([
                'domain' => 'The certificate was not issued; the served certificate is unchanged. '
                    .$result['output'],
            ]);
        }

        return new JsonResponse([
            'data' => [
                'cert_domain' => $result['cert_domain'],
                'url' => $url !== '' ? $url : null,
                'served_certificate' => self::servedCertificate($url),
                'output' => $result['output'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string> the certificate script's flags the request switched on
     */
    private static function certificateFlags(array $params): array
    {
        $flags = [];
        foreach (['staging' => 'staging', 'force_renewal' => 'force-renewal', 'skip_dns_check' => 'skip-dns-check', 'dry_run' => 'dry-run'] as $field => $flag) {
            if (! empty($params[$field])) {
                $flags[] = $flag;
            }
        }

        return $flags;
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
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EngineUpdateRun')],
            )),
            new OA\Response(response: 409, description: 'An update is already running', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
        if (! empty($params['license_key'])) {
            $licenseKey = $params['license_key'];
        }

        $system = app(System::class);

        if ($system->isUpdateScriptRunning()) {
            return new JsonResponse([
                'message' => 'Update script is already running',
            ], 409);
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
            properties: [new OA\Property(
                property: 'new_webserver',
                type: 'string',
                enum: ['nginx-proxy'],
                description: 'Only nginx-proxy can be selected on this engine: every other webserver is temporarily refused because only nginx-proxy works with DinD projects.',
                x: ['mcp-description' => 'Only nginx-proxy is accepted on this engine; every other webserver is temporarily refused.'],
            )],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Webserver change initiated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Any webserver other than nginx-proxy is temporarily refused', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 409, description: 'A webserver change is already running', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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

        self::assertWebserverChoice($params['new_webserver']);
        $serialNumber = self::serialNumberFor($params['new_webserver'], $params['serial_number'] ?? null);

        $system = app(System::class);

        if ($system->isChangeWebserverScriptRunning()) {
            return new JsonResponse(['message' => 'Change webserver script is already running'], 409);
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

    /**
     * Only nginx-proxy is accepted for now, being the one webserver that works with DinD projects.
     *
     * @throws ValidationException
     */
    private static function assertWebserverChoice(string $webserver): void
    {
        if (! in_array($webserver, [
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

        if ($webserver !== 'nginx-proxy') {
            throw ValidationException::withMessages([
                'new_webserver' => 'Switching the webserver away from nginx-proxy is temporarily disabled. Only nginx-proxy is supported right now (required for DinD projects).',
            ]);
        }
    }

    /** @throws ValidationException when a serial number is sent for anything but LiteSpeed */
    private static function serialNumberFor(string $webserver, ?string $serialNumber): ?string
    {
        $serialNumber = ! empty($serialNumber) && trim($serialNumber) !== '' ? trim($serialNumber) : null;

        if ($serialNumber !== null && $webserver !== 'litespeed') {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial number is only supported when switching to LiteSpeed',
            ]);
        }

        return $serialNumber;
    }

    #[OA\Put(
        path: '/system/reset-webserver-panel-password',
        summary: 'Reset the webserver panel password',
        description: 'LiteSpeed and OpenLiteSpeed only. On the nginx-proxy webserver every engine runs today this answers 422, because only (Open)LiteSpeed implements a panel password.',
        x: ['mcp-description' => '(Open)LiteSpeed only; answers 422 on nginx-proxy.'],
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
        $system = new System;
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
        description: 'LiteSpeed and OpenLiteSpeed only. On the nginx-proxy webserver every engine runs today this answers 422, because only (Open)LiteSpeed implements a panel config.',
        x: ['mcp-description' => '(Open)LiteSpeed only; answers 422 on nginx-proxy.'],
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

        $system = new System;
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
        description: 'A stored password or API token (sendgrid_api_token, mailchannels_password, amazon_ses_smtp_password, smtp_password) is never returned: '
            .'it is null, and `<field>_set` says whether one is stored. Sending this answer back to PUT keeps them; PUT `clear_secrets` clears one.',
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
            'data' => EximConfigSecrets::masked(Setting::getEximConfig()),
        ]);
    }

    #[OA\Put(
        path: '/system/exim-config',
        summary: 'Update Exim mail server configuration',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            description: 'Fields are merged into the stored config; one that is absent keeps its value, one sent as null or "" clears it. '
                .'A password or API token (sendgrid_api_token, mailchannels_password, amazon_ses_smtp_password, smtp_password) sent as null or "" '
                .'keeps its stored value, so the GET answer can be sent back unchanged; name it in `clear_secrets` to clear it. '
                .'A password or API token may be a `vault:<key>`; a username (mailchannels_username, amazon_ses_smtp_username, '
                .'smtp_username) is not a secret, and a `vault:` reference sent for one is refused with 422.',
            properties: [
                new OA\Property(property: 'smarthost_provider', type: 'string', nullable: true),
                new OA\Property(property: 'sendgrid_api_token', type: 'string', nullable: true),
                new OA\Property(property: 'mailchannels_username', type: 'string', nullable: true, description: 'The username itself; a `vault:` reference is refused, since a username is not a secret.'),
                new OA\Property(property: 'mailchannels_password', type: 'string', nullable: true),
                new OA\Property(property: 'amazon_ses_smtp_endpoint', type: 'string', nullable: true),
                new OA\Property(property: 'amazon_ses_starttls_port', type: 'string', nullable: true),
                new OA\Property(property: 'amazon_ses_smtp_username', type: 'string', nullable: true, description: 'The username itself; a `vault:` reference is refused, since a username is not a secret.'),
                new OA\Property(property: 'amazon_ses_smtp_password', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_host', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_port', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_username', type: 'string', nullable: true, description: 'The username itself; a `vault:` reference is refused, since a username is not a secret.'),
                new OA\Property(property: 'smtp_password', type: 'string', nullable: true),
                new OA\Property(property: 'smtp_implicit_tls', type: 'boolean', nullable: true),
                new OA\Property(property: 'sender_domain', type: 'string', nullable: true),
                new OA\Property(
                    property: 'clear_secrets',
                    type: 'array',
                    items: new OA\Items(type: 'string', enum: EximConfigSecrets::HIDDEN_FIELDS),
                    nullable: true,
                    description: 'Stored passwords or API tokens to clear.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Config updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Validation error: a host that is not a hostname or an IP, a port that is not 1-65535, a `vault:` reference for a username', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateEximConfig(EximConfigUpdateRequest $request, System $system): JsonResponse
    {
        $params = $request->config();

        Setting::updateEximConfig($params);
        $system->exim()->rebuildEximConfig();

        return new JsonResponse([
            'data' => self::eximConfigAnswer($params, $request->sentCredentials()),
        ]);
    }

    /**
     * The saved fields with each credential as the caller sent it, so a
     * `vault:<key>` is answered as the reference and never as its secret.
     *
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    private static function eximConfigAnswer(array $saved, array $sent): array
    {
        return array_replace($saved, array_intersect_key($sent, $saved));
    }

    #[OA\Post(
        path: '/system/exim-send-test-email',
        summary: 'Send a test email via Exim',
        description: 'exit_code 0 only means Exim accepted the message. status says what happened to it: delivered, deferred (still queued; Exim retries it), failed (bounced), not_sent (Exim did not take it) or unknown; reason is the Exim log line for a deferral or a bounce. '
            .'A `config` sent here is merged into the stored mail config and the Exim config rebuilt before the send, so it is not a throwaway: omit `config` to test the settings already stored, or expect the host\'s mail to run on what you send.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'config', type: 'object', nullable: true, description: 'Merged into the stored mail config and applied (Exim rebuilt) before the send; a field left out keeps its stored value, and so does a password or API token sent as null.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Test email result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 422, description: 'Validation error: the recipient, or a config value PUT /system/exim-config refuses', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function sendTestEmail(EximTestEmailSendRequest $request): JsonResponse
    {
        /**
         * @var array{
         *   email: string,
         *   config?: array
         * } $params
         */
        $params = $request->validated();

        $system = new System;
        self::applyTestEmailConfig($system, $params['config'] ?? null);

        $result = $system->exim()->sendTestEmail($params['email']);

        return new JsonResponse([
            'data' => $result,
        ]);
    }

    /** `config` is optional: without it the test email goes out through the settings already stored. */
    private static function applyTestEmailConfig(System $system, mixed $config): void
    {
        if (is_array($config)) {
            Setting::updateEximConfig($config);
            $system->exim()->rebuildEximConfig();
        }
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

        $system = new System;

        if (! empty($params['default_ipv4'])) {
            Setting::set('default_ipv4', $params['default_ipv4']);
        }
        if (array_key_exists('default_ipv6', $params)) {
            Setting::set('default_ipv6', $params['default_ipv6'] ?? '');
        }

        $reloaded = $system->rebuildDomains();

        $response = $this->info();

        return $response->setData([...(array) $response->getData(true), 'reload_pending' => ! $reloaded]);
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

        $system = new System;
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $map,
            'reload_pending' => ! $reloaded,
        ]);
    }

    public function deleteIpv4NatMap(int $id): JsonResponse
    {
        $map = Ipv4NatMap::findOrFail($id);
        $map->delete();

        $system = new System;
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $map,
            'reload_pending' => ! $reloaded,
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
        $replaceDefaultIpv4 = ! empty($params['replace_default_ipv4']);

        $result = (new System)->network()->rebuildIpv4NatMaps($lookupUrl, $replaceDefaultIpv4);

        $system = new System;
        $reloaded = $system->rebuildDomains();

        return new JsonResponse([
            'data' => $result,
            'reload_pending' => ! $reloaded,
        ]);
    }
}
