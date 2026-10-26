<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Ssl\ProjectCertificate;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordpressMigration;
use Closure;

/**
 * The DNS switch of an imported site, which is the engine's job: once the
 * kept domain resolves to this engine and answers here, request its
 * certificate and remove the preview tunnel. Until then the domain keeps its
 * self-signed certificate and nothing fails.
 */
final class ImportedDomainSwitch
{
    /**
     * @param  (Closure(string): list<string>)|null  $resolveIpv4  the A records of a name
     * @param  (Closure(Domain): void)|null  $requestCertificate  throws when the authority refuses
     */
    public function __construct(
        private ?Closure $resolveIpv4 = null,
        private ?DestinationProbe $probe = null,
        private ?Closure $requestCertificate = null,
        private ?PreviewTunnel $preview = null,
    ) {
        $this->resolveIpv4 ??= self::systemResolver(...);
        $this->probe ??= new DestinationProbe;
        $this->requestCertificate ??= self::requestEligibleCertificate(...);
        $this->preview ??= new PreviewTunnel;
    }

    /**
     * True when the switch happened now. False while the domain does not point
     * here yet; an authority refusal is thrown and the record keeps waiting.
     */
    public function check(WordpressMigration $migration): bool
    {
        $domain = Domain::findByName((string) $migration->target_label);
        if ($domain === null || ! $this->pointsHere($domain) || ! $this->probe->answers('http://'.$domain->domain.'/')) {
            return false;
        }

        ($this->requestCertificate)($domain);
        if (is_string($migration->preview_hostname) && $migration->preview_hostname !== '') {
            $this->preview->close($migration->preview_hostname);
        }
        $migration->markDnsSwitched();

        return true;
    }

    private function pointsHere(Domain $domain): bool
    {
        $resolved = ($this->resolveIpv4)((string) $domain->domain);

        return array_intersect($resolved, self::engineAddresses($domain)) !== [];
    }

    /**
     * @return list<string>
     */
    private static function engineAddresses(Domain $domain): array
    {
        $user = $domain->user;
        $public = $user instanceof User ? $user->getIpAddresses()['ipv4'] : [];

        return array_values(array_filter([...$public, trim((string) Setting::get('default_ipv4'))]));
    }

    /** A name no authority issues for (a shared zone) stays on its current certificate. */
    private static function requestEligibleCertificate(Domain $domain): void
    {
        if (ProjectCertificate::issuer(false)->ineligibleReason((string) $domain->domain) !== null) {
            return;
        }

        ProjectCertificate::request($domain);
    }

    /**
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : $addresses;
    }
}
