<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Hints\Hint;

/** What a visitor's TLS handshake gets for the name. */
final class CheckCertificate extends Hint
{
    public function resolves(): array
    {
        return [PublicUrl::CERTIFICATE_REFUSED];
    }

    public function rest(): string
    {
        return 'GET /projects/{username}/domains/{domain}/ssl-check';
    }
}
