<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\TransferAddress;
use RuntimeException;

/**
 * Step 5: POST remote-sync with the destination URL on the kept domain
 * (incl. subdirectory), this migration's token, and the engine's IPv4 the
 * source pins that name to. The token never reaches step_log. A transfer
 * already holding the source's lock is taken over only when an import on this
 * engine started it; any other one fails the step.
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

        $response = $this->start($this->source->transfer($migration), $destinationUrl, $token);

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

    /**
     * @return array<array-key, mixed>
     */
    private function start(SourceWordpressClient $client, string $destinationUrl, string $token): array
    {
        try {
            return $client->remoteSync($destinationUrl, $token, destinationIp: TransferAddress::ipv4());
        } catch (RuntimeException $e) {
            $holder = SourceWordpressClient::lockHolder($e);
            if ($holder === null) {
                throw $e;
            }
            if (! WordpressMigration::startedPluginMigration($holder)) {
                throw new RuntimeException(
                    "The source site is running a transfer this Engine did not start (migration {$holder}), "
                    .'and the import does not take it over: resume once it has finished or its lock has expired',
                    0,
                    $e,
                );
            }

            return $client->remoteSync($destinationUrl, $token, destinationIp: TransferAddress::ipv4(), force: true);
        }
    }
}
