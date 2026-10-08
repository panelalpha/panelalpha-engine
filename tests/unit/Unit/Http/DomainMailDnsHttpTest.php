<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `GET /projects/{project}/domains/{domain}/mail-dns` without a lookup: what to publish, and where. */
class DomainMailDnsHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeMainDomain($this->makeUser('alice', [], 'shop.example.test'), 'shop.example.test');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_direct_sending_lists_the_records_for_the_domain_itself(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => '203.0.113.10', 'default_ipv6' => null, 'exim' => null]);

        $data = $this->getJson('/api/projects/alice/domains/shop.example.test/mail-dns')->assertOk()->json('data');

        $this->assertSame('shop.example.test', $data['sending_domain']);
        $this->assertSame('direct', $data['relay']);
        $this->assertFalse($data['checked']);
        $this->assertSame(['spf', 'dkim', 'dmarc', 'mx'], array_column($data['records'], 'type'));
        $this->assertSame('v=spf1 ip4:203.0.113.10 ~all', $data['records'][0]['expected']);
        $this->assertArrayNotHasKey('status', $data['records'][0]);
    }

    public function test_a_relay_sender_domain_moves_the_records(): void
    {
        Setting::setRuntimeSettings([
            'default_ipv4' => '203.0.113.10',
            'exim' => json_encode(['smarthost_provider' => 'sendgrid', 'sender_domain' => 'mail.host.example']),
        ]);

        $data = $this->getJson('/api/projects/alice/domains/shop.example.test/mail-dns?dkim_selector=s1')->assertOk()->json('data');

        $this->assertSame('mail.host.example', $data['sending_domain']);
        $this->assertSame('sendgrid', $data['relay']);
        $this->assertSame('v=spf1 include:sendgrid.net ~all', $data['records'][0]['expected']);
        $this->assertSame('s1._domainkey.mail.host.example', $data['records'][1]['host']);
    }

    public function test_an_unknown_domain_or_a_bad_selector_is_refused(): void
    {
        $this->getJson('/api/projects/alice/domains/other.example.test/mail-dns')->assertNotFound();
        $this->getJson('/api/projects/nobody/domains/shop.example.test/mail-dns')->assertNotFound();
        $this->getJson('/api/projects/alice/domains/shop.example.test/mail-dns?dkim_selector=a%20b')
            ->assertUnprocessable()->assertJsonValidationErrors('dkim_selector');
    }
}
