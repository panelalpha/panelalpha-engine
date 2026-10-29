<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\User;
use App\Models\WordpressMigration;
use Throwable;

/**
 * Undoes an import on request: stops the transfer on the source, removes the
 * source plugin (wp_admin only), the preview tunnel, and the project the
 * import created or the files and database it put into an existing one,
 * then clears the secrets. The source is best effort, since it may be
 * unreachable; anything left on this engine keeps the record cancelling
 * so the cancel can be asked again.
 */
final class MigrationCancellation
{
    public const LOG_STEP = 'cancel';

    public function __construct(
        private SourceConnection $source = new SourceConnection,
        private PreviewTunnel $preview = new PreviewTunnel,
        private ImportedSiteRemover $remover = new AccountImportedSiteRemover,
    ) {}

    public function run(WordpressMigration $migration): void
    {
        $this->stopTransfer($migration);
        $this->removeSourcePlugin($migration);
        $this->closePreview($migration);
        $this->removeImportedSite($migration);
        $migration->clearSecrets();
        $migration->appendStepLog(self::LOG_STEP, WordpressMigration::STEP_STATUS_COMPLETED);
        $migration->markCancelled();
    }

    private function stopTransfer(WordpressMigration $migration): void
    {
        $token = $migration->secretsForWorker()['source_token'];
        $pluginMigrationId = $migration->plugin_migration_id;
        if (! $migration->usesManualPlugin() || ! is_string($token) || ! is_string($pluginMigrationId) || $pluginMigrationId === '') {
            return;
        }

        $this->onSource($migration, 'stop the transfer on the source site', function () use ($migration, $pluginMigrationId, $token): void {
            $this->source->open($migration)->stopMigration($pluginMigrationId, $token);
        });
    }

    private function removeSourcePlugin(WordpressMigration $migration): void
    {
        if ($migration->usesManualPlugin() || ! self::sourcePluginTried($migration)) {
            return;
        }

        $this->onSource($migration, 'remove the migrator plugin from the source site', function () use ($migration): void {
            $this->source->admin($migration)->deletePlugin(SourceWordpressClient::PLUGIN_SLUG);
        });
    }

    private function closePreview(WordpressMigration $migration): void
    {
        if (! is_string($migration->preview_hostname) || $migration->preview_hostname === '') {
            return;
        }

        $this->preview->close($migration->preview_hostname);
        $migration->storePreviewHostname(null);
    }

    private function removeImportedSite(WordpressMigration $migration): void
    {
        if ($migration->created_project) {
            $this->deleteCreatedProject($migration);

            return;
        }

        $this->removeFromExistingProject($migration);
    }

    private function deleteCreatedProject(WordpressMigration $migration): void
    {
        $user = ImportedProject::createdBy($migration);
        if ($user instanceof User) {
            $this->remover->deleteProject($user);
        }
    }

    private function removeFromExistingProject(WordpressMigration $migration): void
    {
        $domain = ImportedProject::domainOf($migration);
        $user = $domain?->user;
        if ($domain === null || ! $user instanceof User || ! self::installStarted($migration)) {
            return;
        }

        $this->remover->removeFiles($user, ImportedProject::installPath($user, $domain, $migration->source_url));
        if (is_string($migration->created_database) && $migration->created_database !== '') {
            $this->remover->dropDatabase($user, $migration->created_database, $migration->created_database_user);
        }
    }

    private static function installStarted(WordpressMigration $migration): bool
    {
        return $migration->stepStatus(WordpressMigration::STEP_INSTALL_WORDPRESS) !== WordpressMigration::STEP_STATUS_PENDING;
    }

    private static function sourcePluginTried(WordpressMigration $migration): bool
    {
        return $migration->stepStatus(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN) !== WordpressMigration::STEP_STATUS_PENDING;
    }

    private function onSource(WordpressMigration $migration, string $what, callable $work): void
    {
        try {
            $work();
        } catch (SourceRequestAborted $e) {
            throw $e;
        } catch (Throwable $e) {
            $migration->appendStepLog(
                self::LOG_STEP,
                WordpressMigration::STEP_STATUS_FAILED,
                "Could not {$what}: ".$e->getMessage(),
            );
        }
    }
}
