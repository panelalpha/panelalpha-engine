<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use App\Models\WordpressMigration;
use Closure;

/**
 * Decides, before anything is stored, which domain and project an import
 * lands on: the site keeps its own domain unless the caller names another,
 * an IP source gets a panelalpha.online name only with Connect, and an
 * existing project must be a classic one with nothing installed yet.
 * Refusals are 422 problems the panel can act on.
 */
final class MigrationTargetResolver
{
    /**
     * @param  (Closure(User, Domain, string): bool)|null  $installPathIsEmpty
     *                                                                          whether the project's docroot (and subdirectory) holds no WordPress
     *                                                                          and nothing but a placeholder index.html; tests replace it
     */
    public function __construct(
        private ?Closure $installPathIsEmpty = null,
    ) {
        $this->installPathIsEmpty ??= (new ProjectDocroot)->holdsOnlyPlaceholder(...);
    }

    public function resolve(
        string $sourceUrl,
        ?string $domain = null,
        ?string $project = null,
        ?string $projectName = null,
    ): MigrationTarget {
        $domain = self::normalised($domain);
        if ($project !== null && $project !== '') {
            return $this->existingProject($project, $domain, $sourceUrl);
        }

        $this->assertProjectNameFree($projectName);

        return MigrationTarget::newProject(
            $this->newProjectDomain($sourceUrl, $domain),
            $projectName ?: null,
            $domain !== null,
        );
    }

    public function resolveChoice(string $sourceUrl, TargetChoice $choice): MigrationTarget
    {
        return $this->resolve($sourceUrl, $choice->domain, $choice->project, $choice->projectName);
    }

    /**
     * The domain a site at this home keeps: its host, lowercased, without a
     * leading `www.`. Null when the home is an IP address.
     */
    public static function defaultDomain(string $homeUrl): ?string
    {
        $host = self::normalised(parse_url($homeUrl, PHP_URL_HOST));
        if ($host === null || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Whether a project, tunnel, or unfinished import on this engine already uses the name. */
    public static function domainTaken(string $domain): bool
    {
        return Domain::domainOrAliasExists($domain)
            || Tunnel::hostnameExists($domain)
            || WordpressMigration::query()
                ->where('target_label', $domain)
                ->whereIn('status', WordpressMigration::UNFINISHED_STATUSES)
                ->exists();
    }

    private function newProjectDomain(string $sourceUrl, ?string $domain): ?string
    {
        $domain ??= self::defaultDomain($sourceUrl);
        if ($domain === null) {
            return $this->onlineNameForAnIpSource();
        }

        if (self::domainTaken($domain)) {
            throw ProblemException::one(
                'domain',
                'domain_taken',
                "The domain '{$domain}' already belongs to a project on this engine. Send another `domain`.",
                ['domain' => $domain],
            );
        }

        return $domain;
    }

    private function onlineNameForAnIpSource(): ?string
    {
        if (PanelAlphaConnect::available()) {
            return null;
        }

        throw ProblemException::one(
            'domain',
            'domain_required',
            'The source is an IP address, so there is no domain to keep, and this engine is not connected '
                .'to PanelAlpha Connect to give it a '.PanelAlphaConnect::PARENT_DOMAIN.' name. Send a `domain`.',
        );
    }

    private function existingProject(string $username, ?string $domain, string $sourceUrl): MigrationTarget
    {
        $user = User::findByUsername($username);
        if ($user === null) {
            throw ProblemException::one('project', 'project_not_found', "Project '{$username}' not found.");
        }

        $main = self::mainDomain($user);
        if ($domain !== null && $domain !== $main->domain) {
            throw ProblemException::one(
                'domain',
                'domain_mismatch',
                "An import into project '{$username}' keeps the project's own domain '{$main->domain}'. Omit `domain`.",
            );
        }

        $this->assertEligible($user, $main, SourceUrl::installSubdirectory($sourceUrl));

        return MigrationTarget::existingProject($user->username, (string) $main->domain);
    }

    private function assertEligible(User $user, Domain $main, string $subdir): void
    {
        if ($user->getTemplate() === 'dind') {
            throw ProblemException::one(
                'project',
                'project_not_eligible',
                "Project '{$user->username}' runs in containers. A WordPress import needs a classic project.",
            );
        }

        if (! ($this->installPathIsEmpty)($user, $main, $subdir)) {
            throw ProblemException::one(
                'project',
                'project_not_empty',
                "Project '{$user->username}' already holds a site. A WordPress import needs a docroot with "
                    .'nothing but the placeholder index.html.',
            );
        }
    }

    private static function mainDomain(User $user): Domain
    {
        $main = Domain::findByName((string) $user->domain);
        if ($main === null) {
            throw ProblemException::one(
                'project',
                'project_not_eligible',
                "Project '{$user->username}' has no main domain to import into.",
            );
        }

        return $main;
    }

    private function assertProjectNameFree(?string $projectName): void
    {
        if ($projectName === null || $projectName === '' || ! User::existsByUsername($projectName)) {
            return;
        }

        throw ProblemException::one(
            'project_name',
            'name_taken',
            "A project named '{$projectName}' already exists. Choose another name, or omit `project_name`.",
        );
    }

    private static function normalised(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value, " \t\n\r\0\x0B."));

        return $value === '' ? null : $value;
    }
}
