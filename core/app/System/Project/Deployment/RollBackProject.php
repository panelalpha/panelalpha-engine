<?php

namespace App\System\Project\Deployment;

use App\Models\User as ModelsUser;

/**
 * The template deploy path: tear the account down so a retry can reuse the
 * username instead of colliding with a zombie home directory.
 */
final class RollBackProject implements FailureDisposition
{
    public function afterCancelled(ModelsUser $user, string $message): void
    {
        $this->destroy($user);
    }

    public function afterFailure(ModelsUser $user, string $message): void
    {
        $this->destroy($user);
    }

    /** The template path never kept the log tail of a cancelled deploy. */
    public function hookRunsOnCancel(): bool
    {
        return false;
    }

    public function hookName(): string
    {
        return 'rollback';
    }

    private function destroy(ModelsUser $user): void
    {
        // Re-read: the pipeline may have saved details since, and destroy()
        // works off the row rather than the in-memory model.
        $fresh = ModelsUser::findByUsername($user->username);
        $fresh?->project()->destroy();
    }
}
