<?php

namespace App\Lib\Ssl;

use App\Models\Domain as ModelsDomain;
use App\System\Project\Domain;

/**
 * Certificate operations Issuers need from a Project Domain.
 *
 * Implemented by {@see Domain} on the production path.
 */
interface IssuableDomain
{
    public function model(): ModelsDomain;

    public function generateSelfSignedCertificate(): void;

    public function putCertificate(string $cert, string $key, string $ca = ''): void;

    public function publishCertificateToHostWebserver(): void;
}
