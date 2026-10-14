<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateStatus;
use PHPUnit\Framework\TestCase;

/**
 * The certificate facts a caller reports a finished deploy from.
 *
 * The case this exists for: every project domain on a stock engine gets a
 * certificate the engine signs itself, and the parsed certificate said so only
 * by having the same common name in its issuer as in its subject. A caller
 * that did not make that comparison wrote "issued SSL" for a certificate no
 * browser accepts.
 */
class CertificateStatusTest extends TestCase
{
    private const NOW = 1788700000;

    /** @param array<string, mixed> $overrides */
    private function cert(array $overrides = []): array
    {
        return $overrides + [
            'common_name' => 'app.example.test',
            'issuer_name' => "Let's Encrypt",
            'issuer_common_name' => 'R11',
            'not_before' => self::NOW - 86400,
            'not_after' => self::NOW + (30 * 86400),
            'domains' => ['app.example.test'],
        ];
    }

    public function test_a_certificate_from_an_authority_is_trusted(): void
    {
        $status = CertificateStatus::of($this->cert(), 'app.example.test', self::NOW);

        $this->assertSame(CertificateStatus::TRUSTED, $status['status']);
        $this->assertFalse($status['self_signed']);
        $this->assertSame("Let's Encrypt", $status['issuer']);
        $this->assertSame(30, $status['days_remaining']);
        $this->assertSame('2026-10-06T13:06:40Z', $status['expires_at']);
    }

    /**
     * What the engine generates for every project domain: subject and issuer
     * are the same name.
     */
    public function test_a_certificate_that_issued_itself_is_self_signed(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['issuer_name' => 'PanelAlpha', 'issuer_common_name' => 'app.example.test']),
            'app.example.test',
            self::NOW
        );

        $this->assertSame(CertificateStatus::SELF_SIGNED, $status['status']);
        $this->assertTrue($status['self_signed']);
        $this->assertSame('PanelAlpha', $status['issuer']);
    }

    /**
     * Both true at once, and the reader needs to hear the expiry: a renewal
     * fixes it, believing "self-signed" would send them to install a new one.
     */
    public function test_expired_outranks_self_signed(): void
    {
        $status = CertificateStatus::of(
            $this->cert([
                'issuer_common_name' => 'app.example.test',
                'not_after' => self::NOW - 86400,
            ]),
            'app.example.test',
            self::NOW
        );

        $this->assertSame(CertificateStatus::EXPIRED, $status['status']);
        $this->assertTrue($status['self_signed'], 'the fact itself is not lost');
        $this->assertSame(-1, $status['days_remaining']);
    }

    public function test_a_certificate_not_yet_in_force(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['not_before' => self::NOW + 3600]),
            'app.example.test',
            self::NOW
        );

        $this->assertSame(CertificateStatus::NOT_YET_VALID, $status['status']);
    }

    public function test_a_certificate_for_another_name_does_not_count(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['domains' => ['other.example.test']]),
            'app.example.test',
            self::NOW
        );

        $this->assertSame(CertificateStatus::DOMAIN_MISMATCH, $status['status']);
        $this->assertFalse($status['covers_domain']);
    }

    public function test_a_wildcard_covers_one_label_at_the_front(): void
    {
        $wildcard = $this->cert(['domains' => ['*.example.test']]);

        $this->assertTrue(
            CertificateStatus::of($wildcard, 'app.example.test', self::NOW)['covers_domain']
        );
        $this->assertFalse(
            CertificateStatus::of($wildcard, 'example.test', self::NOW)['covers_domain'],
            'the bare domain is not covered by its own wildcard'
        );
        $this->assertFalse(
            CertificateStatus::of($wildcard, 'a.b.example.test', self::NOW)['covers_domain'],
            'a wildcard is one label, not any depth'
        );
    }

    public function test_names_match_regardless_of_case(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['domains' => ['APP.Example.Test']]),
            'app.example.test',
            self::NOW
        );

        $this->assertTrue($status['covers_domain']);
    }

    public function test_a_validity_window_that_cannot_be_read(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['not_after' => null]),
            'app.example.test',
            self::NOW
        );

        $this->assertSame(CertificateStatus::UNREADABLE, $status['status']);
        $this->assertNull($status['expires_at']);
        $this->assertNull($status['days_remaining']);
    }

    public function test_the_issuer_falls_back_before_giving_up(): void
    {
        $this->assertSame(
            'R11',
            CertificateStatus::of($this->cert(['issuer_name' => 'Unknown']), 'app.example.test', self::NOW)['issuer']
        );
        $this->assertSame(
            'Unknown',
            CertificateStatus::of(
                $this->cert(['issuer_name' => '', 'issuer_common_name' => '']),
                'app.example.test',
                self::NOW
            )['issuer']
        );
    }

    /**
     * An empty issuer common name cannot be compared, so it is not evidence of
     * self-signing either way — and the certificate is judged on the rest.
     */
    public function test_an_unnamed_issuer_is_not_called_self_signed(): void
    {
        $status = CertificateStatus::of(
            $this->cert(['issuer_common_name' => '']),
            'app.example.test',
            self::NOW
        );

        $this->assertFalse($status['self_signed']);
        $this->assertSame(CertificateStatus::TRUSTED, $status['status']);
    }
}
