<?php

namespace App\Lib\Project;

use App\System;

/** Whether a well-formed project name is free, and why when it is not. */
final class ProjectNameCheck
{
    private const MESSAGES = [
        ProjectNameRefusal::TAKEN => 'Project name already in use.',
        ProjectNameRefusal::UNAVAILABLE => 'Project name is not available on this host.',
    ];

    /** @return array{valid: bool, reason?: string, message?: string} */
    public static function answer(string $username): array
    {
        $reason = ProjectNameRefusal::reason($username, app(System::class));

        if ($reason === null) {
            return ['valid' => true];
        }

        return [
            'valid' => false,
            'reason' => $reason,
            'message' => self::MESSAGES[$reason],
        ];
    }
}
