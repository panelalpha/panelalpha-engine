<?php

namespace App\System\Project\Deployment;

use App\Models\User as ModelsUser;
use Illuminate\Support\Facades\Log;

/**
 * ADR-0003: a failed Deploy keeps the Project; only explicit Delete tears it down.
 */
final class FailureRetention
{
    public static function retainAfterDeployFailure(ModelsUser $user, string $message): void
    {
        try {
            $user->setDetails(self::failedDetails($user, $message));
            $user->save();
        } catch (\Throwable $e) {
            Log::warning(
                "Failed to persist deploy failure state for {$user->username}: {$e->getMessage()}",
            );
        }
    }

    /**
     * A failed redeploy does not undo the install: the flag keeps the next
     * deploy in the upgrade phase ({@see \App\Lib\Deploy\Platform\PlatformStage::phaseFor()}).
     *
     * @return array<string, mixed>
     */
    private static function failedDetails(ModelsUser $user, string $message): array
    {
        $details = ['error' => $message, 'deployment_status' => 'failed'];
        if (in_array($user->getDeploymentStatus(), ['success', 'partial'], true)) {
            $details['deployed_before'] = true;
        }

        return $details;
    }

    public static function retainAfterDeployCancelled(ModelsUser $user, string $message): void
    {
        try {
            $user->setDetails(self::failedDetails($user, $message));
            $user->save();
        } catch (\Throwable $e) {
            Log::warning(
                "Failed to persist deploy cancellation state for {$user->username}: {$e->getMessage()}",
            );
        }
    }
}
