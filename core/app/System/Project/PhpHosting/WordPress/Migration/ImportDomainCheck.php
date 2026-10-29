<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;

/**
 * The test's answer to "which domain would the import get": the target the
 * start would resolve, from the site's real home rather than the URL typed.
 * A refusal answers with the start's own problem code as `error_type`.
 */
final class ImportDomainCheck implements SourceProbeCheck
{
    public function __construct(
        private ?MigrationTargetResolver $resolver = null,
    ) {
        $this->resolver ??= new MigrationTargetResolver;
    }

    public function check(SourceAccess $access, SourceSite $site, TargetChoice $choice): array
    {
        try {
            $target = $this->resolver->resolveChoice($site->url, $choice);
        } catch (ProblemException $e) {
            throw self::refusal($e);
        }

        return ['domain' => $target->domain];
    }

    private static function refusal(ProblemException $e): SourceCheckFailed
    {
        $problem = $e->problems[0] ?? [];

        return new SourceCheckFailed(
            (string) ($problem['code'] ?? SourceCheckFailed::DOMAIN_TAKEN),
            (string) ($problem['message'] ?? $e->getMessage()),
        );
    }
}
