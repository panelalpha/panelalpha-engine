<?php

namespace Tests\Integration\Hub;

use App\Http\Controllers\HubSsoController;
use App\Lib\Hub\HubAdminLogin;
use App\Lib\Hub\HubTicket;
use App\Models\HubSsoLogin;
use PHPUnit\Framework\Attributes\DataProvider;

/** A Hub admin login ticket is accepted once, for its app, within its minute, with the grant; every refusal looks alike. */
class HubTicketTest extends HubTestCase
{
    private const NOW = 1795600000;

    private const CONNECTION = 'hc_01J8Z6Q4X9V3K2M7N5P1R0S8T6';

    public function test_a_valid_ticket_is_accepted_once(): void
    {
        $this->pushConnection(['admin_sso']);
        $ticket = $this->ticket();

        $claims = HubTicket::accept($ticket, 'shop', self::NOW);

        $this->assertSame('128', $claims['sub'] ?? null);
        $this->assertNull(HubTicket::accept($ticket, 'shop', self::NOW), 'single use');
        $this->assertSame(['shop', '128'], [HubSsoLogin::query()->sole()->app, HubSsoLogin::query()->sole()->sub]);
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('refusals')]
    public function test_every_check_refuses_on_its_own(array $claims, string $app = 'shop', ?string $secret = null, ?array $header = null): void
    {
        $this->pushConnection(['admin_sso']);

        $this->assertNull(HubTicket::accept($this->ticket($claims, $secret, $header), $app, self::NOW));
        $this->assertSame(0, HubSsoLogin::query()->count());
    }

    /** @return array<string, array<int, mixed>> */
    public static function refusals(): array
    {
        return [
            'another algorithm' => [[], 'shop', null, ['alg' => 'HS512', 'typ' => 'JWT']],
            'none algorithm' => [[], 'shop', null, ['alg' => 'none', 'typ' => 'JWT']],
            'another secret' => [[], 'shop', str_repeat('c', 64)],
            'another issuer' => [['iss' => 'https://evil.example']],
            'another connection' => [['aud' => 'hc_OTHER']],
            'another purpose' => [['purpose' => 'deploy']],
            'another app' => [[], 'blog'],
            'expired' => [['exp' => self::NOW - 31]],
            'issued in the future' => [['iat' => self::NOW + 31]],
            'short jti' => [['jti' => 'abc']],
            'no jti' => [['jti' => null]],
        ];
    }

    public function test_a_connection_without_the_grant_or_none_at_all_accepts_nothing(): void
    {
        $this->assertNull(HubTicket::accept($this->ticket(), 'shop', self::NOW));

        $this->pushConnection();
        $this->assertNull(HubTicket::accept($this->ticket(), 'shop', self::NOW));
    }

    public function test_the_previous_secret_signs_only_within_its_minutes(): void
    {
        $connection = $this->pushConnection(['admin_sso']);
        $connection->forceFill(['hub_secret' => str_repeat('b', 64), 'hub_secret_previous' => self::SECRET, 'hub_secret_previous_until' => now()->addMinutes(5)])->save();

        $this->assertNotNull(HubTicket::accept($this->ticket(), 'shop', self::NOW));

        $connection->forceFill(['hub_secret_previous_until' => now()->subMinute()])->save();
        $this->assertNull(HubTicket::accept($this->ticket(['jti' => str_repeat('z', 22)]), 'shop', self::NOW));
    }

    public function test_every_refusal_on_the_app_domain_is_the_same_page(): void
    {
        $this->pushConnection(['admin_sso']);
        $this->project('shop');

        $responses = [
            $this->post('/api/internal/hub-sso/shop', []),
            $this->post('/api/internal/hub-sso/shop', ['ticket' => 'not.a.ticket']),
            $this->post('/api/internal/hub-sso/nobody', ['ticket' => $this->ticket()]),
            $this->post('http://not-its-domain.example/api/internal/hub-sso/shop', ['ticket' => $this->ticket()]),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(403)->assertSee(HubSsoController::REFUSED)->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_the_administrator_is_the_user_whose_role_comes_first(): void
    {
        $users = [
            ['id' => 1, 'username' => 'editor', 'role' => 'editor'],
            ['id' => 2, 'username' => 'boss', 'role' => 'Owner'],
            ['id' => 3, 'username' => 'root', 'role' => 'ADMINISTRATOR'],
        ];

        $this->assertSame(['id' => '3', 'username' => 'root'], HubAdminLogin::pickAdmin($users));
        $this->assertNull(HubAdminLogin::pickAdmin([['id' => 1, 'username' => 'editor', 'role' => 'editor']]));
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, string>|null  $header
     */
    private function ticket(array $claims = [], ?string $secret = null, ?array $header = null): string
    {
        $claims = array_filter($claims + [
            'iss' => self::HUB,
            'aud' => self::CONNECTION,
            'sub' => '128',
            'purpose' => 'admin_sso',
            'app' => 'shop',
            'jti' => 'R7mQ0x9Wc2yH7tLr1aVbN4pS',
            'iat' => self::NOW,
            'exp' => self::NOW + 60,
        ], fn (mixed $value): bool => $value !== null);
        $segments = [self::b64((string) json_encode($header ?? ['alg' => 'HS256', 'typ' => 'JWT'])), self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $secret ?? self::SECRET, true));

        return implode('.', $segments);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
