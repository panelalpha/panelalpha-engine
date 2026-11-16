<?php

namespace App\System\Project\Deployment;

use App\Models\User as ModelsUser;

/**
 * What becomes of a project whose deploy did not finish, the one step the two deploy paths disagree on: DinD keeps
 * the project ({@see RetainProject}, ADR-0003), a template rolls it back ({@see RollBackProject}) so a retry can reuse the name.
 */
interface FailureDisposition
{
    public function afterCancelled(ModelsUser $user, string $message): void;

    public function afterFailure(ModelsUser $user, string $message): void;

    /** Whether the caller's hook runs before a cancelled deploy is cleaned up, as it does before a failed one. */
    public function hookRunsOnCancel(): bool;

    /** How the hook is named when it fails: `rollback` or `retention`. */
    public function hookName(): string;
}
