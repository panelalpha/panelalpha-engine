<?php

namespace Tests\Unit\Hub;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubTokens;
use App\Lib\Hub\SpkiPin;
use App\Lib\Project\ProjectOverview;
use App\Models\Admin;
use App\Models\HubConnection;
use App\Models\Setting;
use App\Models\User;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\Ufw\UfwFirewall;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use ReflectionProperty;
use Tests\Support\Fail2banHost;
use Tests\TestCase;

/**
 * The whole schema on an in-memory sqlite database -- what a fresh install
 * runs -- so the Hub routes and commands are driven end to end with real
 * tokens. The Hub itself is Http::fake().
 */
abstract class HubTestCase extends TestCase
{
    public const HUB = 'https://connect.example.test';
    public const APP_ID = '5f0c2a8e-3b1d-4a8e-9c61-0d7b2f4a9e13';
    public const SECRET = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    public const ENGINE_IP = '93.184.216.34';

    protected string $certificate = '';

    /** The host the firewall writes fail2ban's lists on; never the real one. */
    protected Fail2banHost $fail2ban;

    /** @var list<string> */
    private array $locks = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.uid' => self::APP_ID,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'hub.url' => self::HUB,
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        Setting::setRuntimeSettings(['default_ipv4' => self::ENGINE_IP]);
        $this->fakeDockerInfo(['Name' => 'srv1.acme.com', 'OperatingSystem' => 'Ubuntu 24.04.1 LTS', 'KernelVersion' => '6.8.0-45-generic']);
        ProjectOverview::fakeRunning([]);

        $this->certificate = $this->makeCertificate();
        SpkiPin::$servedPathOverride = $this->certificate;

        $this->fakeFirewallHost();
    }

    protected function tearDown(): void
    {
        SpkiPin::$servedPathOverride = null;
        Setting::clearRuntimeSettings();
        ProjectOverview::fakeRunning(false);
        HostFacts::resetProbeCache();
        @unlink($this->certificate);
        FirewallFactory::reset();
        array_map('unlink', $this->locks);

        parent::tearDown();
    }

    /** @param array<int, string> $grants */
    protected function hubToken(array $grants = [], string $connectionId = 'hc_TEST'): NewAccessToken
    {
        return HubTokens::mint(HubGrants::abilities($grants), HubTokens::nameFor($connectionId));
    }

    /** @param array<int, string> $grants */
    protected function connection(NewAccessToken $token, array $grants = [], string $connectionId = 'hc_TEST'): HubConnection
    {
        return HubConnection::query()->create([
            'connection_id' => $connectionId,
            'hub_url' => self::HUB,
            'hub_secret' => self::SECRET,
            'account_id' => '42',
            'account_name' => 'Acme Ltd',
            'approved_by' => 'j***@acme.com',
            'ip' => self::ENGINE_IP,
            'tls_spki' => 'sha256/old',
            'grants' => $grants,
            'token_id' => $token->accessToken->id,
            'status' => HubConnection::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);
    }

    protected function adminToken(): string
    {
        return Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;
    }

    /** @param array<string, mixed> $details */
    protected function project(string $username, array $details = ['deploy_strategy' => 'php'], string $status = 'active'): User
    {
        $user = new User();
        $user->forceFill([
            'username' => $username,
            'domain' => $username . '.example.com',
            'details' => $details,
            'status' => $status,
        ])->save();

        return $user;
    }

    protected function makeCertificate(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => self::ENGINE_IP], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $pem);

        $path = tempnam(sys_get_temp_dir(), 'hub-cert-');
        file_put_contents($path, $pem);

        return $path;
    }

    private function fakeFirewallHost(): void
    {
        $this->fail2ban = new Fail2banHost();
        $this->locks = [(string) tempnam(sys_get_temp_dir(), 'ufw-lock'), (string) tempnam(sys_get_temp_dir(), 'ufw-trusted-lock')];
        FirewallFactory::register(FirewallFactory::UFW, fn () => new UfwFirewall($this->fail2ban, $this->locks[0], 30, $this->locks[1]));
        config(['env.FIREWALL_PROVIDER' => FirewallFactory::UFW]);
    }

    /** @param array<string, mixed> $info */
    private function fakeDockerInfo(array $info): void
    {
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, $info);
    }
}
