<?php

namespace App\System\Project\Deployment;

use App\Models\User as ModelsUser;

/** ADR-0003: a failed DinD deploy keeps the project; only explicit Delete tears it down. */
final class RetainProject implements FailureDisposition
{
    public function afterCancelled(ModelsUser $user, string $message): void
    {
        FailureRetention::retainAfterDeployCancelled($user, $message);
    }

    public function afterFailure(ModelsUser $user, string $message): void
    {
        FailureRetention::retainAfterDeployFailure($user, $message);
    }

    public function hookRunsOnCancel(): bool
    {
        return true;
    }

    public function hookName(): string
    {
        return 'retention';
    }
}
