<?php

namespace App\System\Project;

use App\Models\User;
use App\System\Project;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Dind\ProvisionedAppDatabase;
use App\System\Project\PhpHosting\WordPress\KeptSite;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\TableReplacement;

/**
 * One push from a source project into a target: what it copies, where it
 * stages the copy, and what it has changed on the target so far, which is
 * what a rollback undoes.
 */
final class PushAttempt
{
    /** @var array<string, string> destination database => rollback dump */
    public array $rollbacks = [];

    /** @var list<string> */
    public array $createdDatabases = [];

    public ?ProvisionedAppDatabase $provisioned = null;

    public ?TableReplacement $tableReplacement = null;

    public ?HeldImage $heldImage = null;

    public bool $volumesSwapStarted = false;

    /** Whether the target started on what the push copied, so a rollback starts it again on its own data. */
    public bool $targetStarted = false;

    /** Rollback dumps a failed push left behind, which this push refuses to overwrite. */
    public bool $keptRollbacks = false;

    public DatabaseCopyResult $result;

    public ?KeptSite $keptSite = null;

    /** @var list<string> */
    public array $containerHosts = [];

    public readonly string $incomingDir;

    public readonly string $prePushDir;

    public readonly string $rollbackDir;

    /**
     * @param  list<array{database: string, name: string}>|null  $tables
     * @param  array<string, mixed>  $targetSnapshot  the target's deploy fields before the push
     */
    public function __construct(
        public readonly User $from,
        public readonly User $to,
        public readonly bool $files,
        public readonly bool $databases,
        public readonly ?array $tables,
        public readonly array $targetSnapshot,
        public readonly Project $fromProject,
        public readonly Project $toProject,
        public readonly string $fromHome,
        public readonly string $toHome,
        public readonly bool $isDindDest,
        public readonly HomeSwap $homeSwap,
    ) {
        $this->incomingDir = $toHome.'/.incoming';
        $this->prePushDir = $toHome.'/.pre-push';
        $this->rollbackDir = $toHome.'/.pre-push-db';
        $this->result = new DatabaseCopyResult;
    }

    /** Whether the push copies databases one table at a time. */
    public function replacesTables(): bool
    {
        return $this->tables !== null && $this->tables !== [];
    }
}
