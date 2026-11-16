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

    /** The rollback deletes the deploy log, so a cancel needs the tail kept as much as a failure. */
    public function hookRunsOnCancel(): bool
    {
        return true;
    }

    public function hookName(): string
    {
        return 'rollback';
    }

    /**
     * Re-read by id: the pipeline may have saved details since, destroy() works off the row, and a newer
     * project may have taken the name of one deleted under this deploy.
     */
    private function destroy(ModelsUser $user): void
    {
        $fresh = $user->getKey() === null ? null : ModelsUser::query()->whereKey($user->getKey())->first();
        $fresh?->project()->destroy();
    }
}
