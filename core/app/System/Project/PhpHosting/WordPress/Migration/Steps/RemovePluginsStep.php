<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Uninstall wordpress-migrator on the source (wp_admin only) and the destination, both tried so one failing never
 * leaves the other exposed. A source plugin the import did not install stays ({@see WordpressMigration::keepsSourcePlugin}).
 */
final class RemovePluginsStep implements MigrationStep
{
    use LocatesDestinationInstall;

    public function __construct(
        private SourceConnection $source = new SourceConnection,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_REMOVE_PLUGINS;
    }

    public function run(WordpressMigration $migration): void
    {
        $failures = [];
        if (! $migration->keepsSourcePlugin()) {
            $failures[] = self::attempt(fn () => $this->removeSourcePlugin($migration));
        }
        $failures[] = self::attempt(fn () => $this->removeDestinationPlugin($migration));
        self::throwAny(array_values(array_filter($failures)));

        $migration->markStepCompleted($this->name());
    }

    private static function attempt(Closure $removal): ?Throwable
    {
        try {
            $removal();

            return null;
        } catch (SourceRequestAborted $e) {
            throw $e;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * @param  list<Throwable>  $failures
     */
    private static function throwAny(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        throw new RuntimeException(
            implode('; ', array_map(static fn (Throwable $failure): string => $failure->getMessage(), $failures)),
            0,
            $failures[0],
        );
    }

    private function removeDestinationPlugin(WordpressMigration $migration): void
    {
        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException('Cannot remove destination plugin: target_label is missing');
        }

        $domain = Domain::findByName($label);
        if ($domain === null) {
            throw new RuntimeException("Domain '{$label}' not found for destination plugin removal");
        }

        $user = $this->destinationUser($domain, $label);
        $path = $this->destinationPath($migration, $user, $domain);

        $wp = WordPress::for($user, $path);
        $wp->plugins()->deactivate(SourceWordpressClient::PLUGIN_SLUG);
        $wp->plugins()->delete(SourceWordpressClient::PLUGIN_SLUG);
    }

    private function removeSourcePlugin(WordpressMigration $migration): void
    {
        $this->source->admin($migration)->deletePlugin(SourceWordpressClient::PLUGIN_SLUG);
    }
}
