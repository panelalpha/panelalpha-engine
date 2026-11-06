<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Hints\Hint;

/** A trusted certificate, which needs the name to resolve to this host first. */
final class RequestCertificate extends Hint
{
    public function resolves(): array
    {
        return [PublicUrl::CERTIFICATE_REFUSED];
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/domains/{domain}/request-ssl-cert';
    }

    public function cli(): string
    {
        return 'ssl:project-cert:request {domain}';
    }

    public function unless(): array
    {
        return [PublicUrl::UNRESOLVABLE];
    }
}
