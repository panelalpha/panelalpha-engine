<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * The test's answer to whether the source site could send the transfer to this
 * engine at all, with the start's own refusal as `error_type`.
 */
final class TransferAddressCheck implements SourceProbeCheck
{
    public function check(SourceAccess $access, SourceSite $site, TargetChoice $choice): array
    {
        $refusal = TransferAddress::refusal();
        if ($refusal !== null) {
            throw new SourceCheckFailed(TransferAddress::UNREACHABLE, $refusal);
        }

        return [];
    }
}
