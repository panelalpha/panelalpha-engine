<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\PublicUrl;
use App\Lib\Ssl\CertificateStatus;
use PHPUnit\Framework\TestCase;

class PublicUrlTest extends TestCase
{
    /**
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $ssl
     * @return array<string, mixed>
     */
    private function details(array $domain = [], array $ssl = []): array
    {
        return [
            'domain' => array_replace([
                'source' => DomainPlan::SOURCE_PANELALPHA_ONLINE,
                'publicly_resolvable' => true,
                'tls_terminated_at' => 'proxy',
                'tunnel' => 'panelalpha',
                'valid_until' => null,
                'fallback_reason' => null,
            ], $domain),
            'ssl' => array_replace([
                'domain' => 'shop-4f2a.panelalpha.online',
                'status' => CertificateStatus::SELF_SIGNED,
                'issuer' => 'PanelAlpha',
                'self_signed' => true,
            ], $ssl),
        ];
    }

    public function test_a_proxied_online_name_is_a_clean_success(): void
    {
        // The local certificate is self-signed and always will be: the proxy
        // terminates TLS, so nobody is ever shown this one.
        $this->assertSame([], PublicUrl::warnings('shop-4f2a.panelalpha.online', $this->details()));
    }

    public function test_an_operators_own_base_domain_is_a_clean_success(): void
    {
        $warnings = PublicUrl::warnings('shop.hoster.example', $this->details(
            [
                'source' => DomainPlan::SOURCE_SITES_BASE_DOMAIN,
                // The engine does not control this zone and says so.
                'publicly_resolvable' => null,
                'tls_terminated_at' => 'engine',
            ],
            ['status' => CertificateStatus::TRUSTED, 'issuer' => "Let's Encrypt", 'self_signed' => false],
        ));

        $this->assertSame([], $warnings);
    }

    public function test_the_licensing_fallback_warns_about_both_the_name_and_the_certificate(): void
    {
        $warnings = PublicUrl::warnings('shop.local', $this->details(
            [
                'source' => DomainPlan::SOURCE_LOCAL,
                'publicly_resolvable' => false,
                'tls_terminated_at' => 'engine',
                'tunnel' => null,
                'fallback_reason' => 'panelalpha_online: PanelAlpha Online create failed: License is not valid (HTTP 403)',
            ],
            ['domain' => 'shop.local'],
        ));

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('not reachable from the internet', $warnings[0]);
        $this->assertStringContainsString('shop.local', $warnings[0]);
        // The reason travels with the warning: this is what tells an operator
        // Connect refused them rather than that the host has no address.
        $this->assertStringContainsString('License is not valid (HTTP 403)', $warnings[0]);
        $this->assertStringContainsString('browsers will refuse', $warnings[1]);
        $this->assertStringContainsString('self_signed', $warnings[1]);
    }

    /**
     * A LAN name and a name nobody can resolve are different situations, and
     * the sentence has to tell them apart: `.direct` on a private address
     * answers for every machine on that network.
     */
    public function test_a_direct_name_on_a_private_address_says_this_network_rather_than_this_host(): void
    {
        $warnings = PublicUrl::warnings('shop.10-0-0-4.panelalpha.direct', $this->details(
            [
                'source' => DomainPlan::SOURCE_PANELALPHA_DIRECT,
                'publicly_resolvable' => false,
                'tls_terminated_at' => 'engine',
                'fallback_reason' => "no public IPv4: the host's address is private",
            ],
            ['status' => CertificateStatus::TRUSTED, 'self_signed' => false],
        ));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('answers on this network only', $warnings[0]);
        $this->assertStringNotContainsString('resolves on this host only', $warnings[0]);
    }

    public function test_an_unresolvable_name_warns_even_without_a_recorded_reason(): void
    {
        $warnings = PublicUrl::warnings('shop.local', $this->details(
            ['publicly_resolvable' => false, 'tls_terminated_at' => 'engine', 'fallback_reason' => null],
            ['status' => CertificateStatus::TRUSTED, 'self_signed' => false],
        ));

        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString('skipped', $warnings[0]);
    }

    public function test_expired_and_mismatched_certificates_warn_too(): void
    {
        foreach ([CertificateStatus::EXPIRED, CertificateStatus::DOMAIN_MISMATCH, CertificateStatus::UNTRUSTED_ISSUER] as $status) {
            $warnings = PublicUrl::warnings('shop.hoster.example', $this->details(
                ['publicly_resolvable' => null, 'tls_terminated_at' => 'engine'],
                ['status' => $status],
            ));

            $this->assertCount(1, $warnings, $status);
            $this->assertStringContainsString($status, $warnings[0]);
        }
    }

    public function test_a_certificate_that_could_not_be_read_is_not_a_warning(): void
    {
        // AppCertificate is advisory; a deploy that worked is not undone by a
        // file we could not parse, or by SSL being switched off entirely.
        foreach ([CertificateStatus::UNREADABLE, 'missing'] as $status) {
            $warnings = PublicUrl::warnings('shop.hoster.example', $this->details(
                ['publicly_resolvable' => null, 'tls_terminated_at' => 'engine'],
                ['status' => $status],
            ));

            $this->assertSame([], $warnings, $status);
        }
    }

    public function test_a_project_created_before_the_allocator_recorded_anything_is_left_alone(): void
    {
        $this->assertSame([], PublicUrl::warnings('shop.hoster.example', []));
        $this->assertSame([], PublicUrl::warnings('shop.hoster.example', ['domain' => 'shop.hoster.example']));
    }
}
