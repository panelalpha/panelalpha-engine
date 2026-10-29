<?php

namespace App\System\Project\Dind;

use App\Lib\Ssl\CertificateStatus;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Records the main domain's certificate snapshot on the account (advisory, like AppHealth).
 */
final class AppCertificate
{
    public const DETAIL = 'ssl';

    public function __construct(
        private DindProject $project,
    ) {}

    public function remember(): void
    {
        try {
            $user = $this->project->userModel();
            $domainModel = $user->getMainDomain();

            if ($domainModel === null) {
                return;
            }

            $domain = $this->project->domain($domainModel);
            $snapshot = $domain->hasSslCertificate()
                ? self::snapshot($domain->getSslCertificateInfo(), $domainModel->domain)
                : self::missing($domainModel->domain);

            $user->setDetails([self::DETAIL => $snapshot]);
            $user->save();
        } catch (\Throwable $e) {
            Log::debug('Could not record the certificate status: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $cert
     * @return array<string, mixed>
     */
    private static function snapshot(array $cert, string $domain): array
    {
        $status = CertificateStatus::of($cert, $domain);

        return ['domain' => $domain] + $status;
    }

    /**
     * @return array<string, mixed>
     */
    private static function missing(string $domain): array
    {
        return [
            'domain' => $domain,
            'status' => 'missing',
            'issuer' => 'Unknown',
            'self_signed' => false,
            'covers_domain' => false,
            'valid_from' => null,
            'expires_at' => null,
            'days_remaining' => null,
        ];
    }
}
