<?php

namespace App\Lib\Ssl;

use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\NamingBaseSetting;
use App\Models\Setting;
use App\Support\EngineEnvFile;
use App\Support\EngineSetting;

/**
 * The address this engine answers on and the name its certificate is issued for, for `pae configure address` and
 * the API alike.
 *
 * `APP_URL` is the address every assistant and API client is handed, in `.env-core`. `cert_domain` names the
 * certificate and every project site created from now on, `cert_email` is the Let's Encrypt contact, and
 * `default_tunnel` is where a project created without a tunnel is published; those three are settings.
 */
class EngineAddressSettings
{
    public const URL_KEY = 'APP_URL';

    public const CERT_DOMAIN = 'cert_domain';

    public const CERT_EMAIL = 'cert_email';

    private const HOSTNAME = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public function __construct(private readonly ?EngineEnvFile $env = null) {}

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Sets APP_URL, adopted by this process too; the engine reads it on its next request. */
    public function setUrl(string $url): void
    {
        $this->env()->set([self::URL_KEY => $url]);
        config(['app.url' => $url]);
    }

    public function certificateName(): string
    {
        return (string) (Setting::get(self::CERT_DOMAIN) ?? '');
    }

    public function certificateEmail(): string
    {
        return (string) (Setting::get(self::CERT_EMAIL) ?? '');
    }

    /** Stores the certificate name, empty for the default; a change re-renders every vhost. */
    public function setCertificateName(string $name): void
    {
        NamingBaseSetting::save(self::CERT_DOMAIN, strtolower(trim($name)));
    }

    public function setCertificateEmail(string $email): void
    {
        Setting::set(self::CERT_EMAIL, strtolower(trim($email)));
    }

    /** `none` when set so, `panelalpha` otherwise: an unset setting behaves as panelalpha. */
    public function defaultTunnel(): string
    {
        return Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL) === DomainPlan::TUNNEL_NONE
            ? DomainPlan::TUNNEL_NONE
            : DomainPlan::TUNNEL_PANELALPHA;
    }

    public function setDefaultTunnel(string $tunnel): void
    {
        Setting::set(DomainPlan::SETTING_DEFAULT_TUNNEL, $tunnel);
    }

    /**
     * Applies each given field; one left out stays as it is.
     *
     * @param  array{url?: string, cert_domain?: string, cert_email?: string, default_tunnel?: string}  $changes
     */
    public function set(array $changes): void
    {
        if (array_key_exists('url', $changes)) {
            $this->setUrl($changes['url']);
        }
        if (array_key_exists('cert_domain', $changes)) {
            $this->setCertificateName($changes['cert_domain']);
        }
        if (array_key_exists('cert_email', $changes)) {
            $this->setCertificateEmail($changes['cert_email']);
        }
        if (array_key_exists('default_tunnel', $changes)) {
            $this->setDefaultTunnel($changes['default_tunnel']);
        }
    }

    /**
     * @return array{
     *     url: array{value: ?string, source: string},
     *     cert_domain: array{value: ?string, source: string},
     *     cert_email: array{value: ?string, source: string},
     *     default_tunnel: array{value: string, source: string},
     *     served_certificate: ?array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $url = $this->url();

        return [
            'url' => ['value' => $url === '' ? null : $url, 'source' => in_array($this->env()->get(self::URL_KEY), [null, ''], true) ? EngineSetting::DEFAULT : EngineSetting::ENV],
            'cert_domain' => $this->stored(self::CERT_DOMAIN),
            'cert_email' => $this->stored(self::CERT_EMAIL),
            'default_tunnel' => [
                'value' => $this->defaultTunnel(),
                'source' => Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL) === null ? EngineSetting::DEFAULT : EngineSetting::SETTING,
            ],
            'served_certificate' => $url === '' ? null : ServedCertificate::at($url),
        ];
    }

    /** Why $url cannot be the engine's address, or null when it can; empty is accepted as no change. */
    public static function badUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || ! str_contains($url, '://')) {
            return 'Give the whole address, including https:// and the port.';
        }

        return null;
    }

    /** Why no client could use $url, or null when it names a host something else can reach. */
    public static function unreachableUrl(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, self::LOCAL_HOSTS, true)
            ? 'No client can reach this engine at that address. Give the name or public address clients connect on.'
            : null;
    }

    /**
     * Why $name cannot be the certificate name, or null when it can; empty is the default. Strict about the shape:
     * two labels at least, letters, digits and hyphens, no leading or trailing dot.
     */
    public static function badName(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        if (! str_contains($name, '.')) {
            return 'A certificate name is a full hostname, like panel.example.com.';
        }

        return preg_match(self::HOSTNAME, $name) === 1
            ? null
            : 'That is not a hostname. Letters, digits and hyphens, in labels separated by dots.';
    }

    /** @return array{value: ?string, source: string} */
    private function stored(string $key): array
    {
        $value = Setting::get($key);

        return [
            'value' => is_string($value) && $value !== '' ? $value : null,
            'source' => $value === null ? EngineSetting::DEFAULT : EngineSetting::SETTING,
        ];
    }

    private function env(): EngineEnvFile
    {
        return $this->env ?? EngineEnvFile::core();
    }
}
