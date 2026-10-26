<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * One more question the test endpoint asks once the source has been found
 * (the target domain, say), given the target the caller chose. Adds fields to
 * the `ok: true` answer, or throws {@see SourceCheckFailed} with its own
 * `error_type` to answer `ok: false`.
 */
interface SourceProbeCheck
{
    /**
     * @return array<string, mixed>
     */
    public function check(SourceAccess $access, SourceSite $site, TargetChoice $choice): array;
}
