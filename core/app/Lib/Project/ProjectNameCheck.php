<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System;

/** Whether a well-formed project name is free, and why when it is not. */
final class ProjectNameCheck
{
    private const MESSAGES = [
        'taken' => 'Project name already in use.',
        'unavailable' => 'Project name is not available on this host.',
    ];

    /** @return array{valid: bool, reason?: string, message?: string} */
    public static function answer(string $username): array
    {
        $reason = self::unavailableReason($username);

        if ($reason === null) {
            return ['valid' => true];
        }

        return [
            'valid' => false,
            'reason' => $reason,
            'message' => self::MESSAGES[$reason],
        ];
    }

    /** `taken` when a project uses the name, `unavailable` when the host reserves it, null when it is free. */
    private static function unavailableReason(string $username): ?string
    {
        if (User::existsByUsername($username)) {
            return 'taken';
        }

        return app(System::class)->isUsernameAvailable($username) ? null : 'unavailable';
    }
}
