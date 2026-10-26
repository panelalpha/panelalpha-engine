<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Lib\Deploy\HostingUsername;
use App\Lib\Helper;
use App\Models\Domain;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\ApiMigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\ImportedProject;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use Closure;
use RuntimeException;

/**
 * Step 1: create the target project on the kept domain (or adopt the
 * existing one the caller named), then open a preview tunnel for that domain
 * when the engine has Connect. target_label is the project's domain. The
 * project's name is stored before it is created, so a rerun after a crash
 * adopts the project it created instead of failing on a taken name.
 */
final class CreateProjectStep implements MigrationStep
{
    private const FALLBACK_NAME = 'wordpress';

    /**
     * @param  (Closure(string): bool)|null  $usernameAvailable
     */
    public function __construct(
        private ?MigrationProjectCreator $creator = null,
        private ?PreviewTunnel $preview = null,
        private ?Closure $usernameAvailable = null,
    ) {
        $this->creator ??= new ApiMigrationProjectCreator;
        $this->preview ??= new PreviewTunnel;
        $this->usernameAvailable ??= Helper::usernameAvailable(...);
    }

    public function name(): string
    {
        return WordpressMigration::STEP_CREATE_PROJECT;
    }

    public function run(WordpressMigration $migration): void
    {
        if ($migration->created_project) {
            $this->createProject($migration);
        }

        $this->openPreview($migration);
        $migration->markStepCompleted($this->name());
    }

    private function createProject(WordpressMigration $migration): void
    {
        $username = $this->projectName($migration);
        $migration->storeTargetProject($username);
        $created = ImportedProject::createdBy($migration);
        $domain = $created?->domain ?? $this->creator->create($username, $migration->target_label);
        $migration->storeTarget($username, (string) $domain);
    }

    /**
     * The name the caller gave, else one derived from the domain (or the
     * source host), suffixed when that one is taken.
     */
    private function projectName(WordpressMigration $migration): string
    {
        if (is_string($migration->target_project) && $migration->target_project !== '') {
            return $migration->target_project;
        }

        $base = HostingUsername::normalize((string) $migration->target_label)
            ?? SourceUrl::projectUsername($migration->source_url)
            ?? self::FALLBACK_NAME;

        return Helper::generateUsernameFrom($base, $this->usernameAvailable)
            ?? throw new RuntimeException("No free project name could be derived from '{$base}'.");
    }

    private function openPreview(WordpressMigration $migration): void
    {
        if (! $migration->keepsItsOwnDomain() || $migration->preview_hostname !== null) {
            return;
        }

        $domain = Domain::findByName((string) $migration->target_label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$migration->target_label}' not found for the preview tunnel.");
        }

        $migration->storePreviewHostname($this->preview->open($domain));
    }
}
