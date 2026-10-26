<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Setting;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use RuntimeException;

/**
 * Step 5: POST remote-sync with the destination URL on the kept domain
 * (incl. subdirectory), this migration's token, and the engine's IPv4 the
 * source pins that name to. The token never reaches step_log.
 */
final class RemoteSyncStep implements MigrationStep
{
    public function __construct(
        private SourceConnection $source = new SourceConnection,
    ) {}

    public function name(): string
    {
        return WordpressMigration::STEP_REMOTE_SYNC;
    }

    public function run(WordpressMigration $migration): void
    {
        $secrets = $migration->secretsForWorker();
        $token = $secrets['migrator_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Cannot remote-sync: migrator token is missing');
        }

        $label = $migration->target_label;
        if (! is_string($label) || $label === '') {
            throw new RuntimeException('Cannot remote-sync: target_label is missing');
        }

        $destinationUrl = SourceUrl::destinationSiteUrl($label, $migration->source_url);

        $response = $this->source->open($migration)->remoteSync($destinationUrl, $token, destinationIp: self::engineIpv4());

        if (($response['success'] ?? null) !== true) {
            $message = is_string($response['message'] ?? null)
                ? $response['message']
                : 'remote-sync was not successful';
            throw new RuntimeException($message);
        }
        if (
            ! is_string($response['migration_id'] ?? null)
            || $response['migration_id'] === ''
        ) {
            throw new RuntimeException('remote-sync did not return a migration_id');
        }

        $migration->storePluginMigrationId($response['migration_id']);
        $migration->markStepCompleted($this->name());
    }

    /** The engine's public IPv4, where the source sends the destination's name; null when none is recorded. */
    private static function engineIpv4(): ?string
    {
        $ipv4 = trim((string) Setting::get('default_ipv4'));

        return filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $ipv4;
    }
}
