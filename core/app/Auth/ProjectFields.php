<?php

namespace App\Auth;

/**
 * Operations that name a project in the body or the query, and the field it is
 * in -- `POST /{alice}/push {"target":"bob"}` acts on bob, not on alice.
 *
 * One map, read by both middlewares that ask about the projects a request
 * names: the token-scope check and the protected-project check.
 */
class ProjectFields
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'POST /projects/{username}/push' => ['target'],
        'GET /tasks' => ['username'],
    ];

    /**
     * The fields of one operation, or [] when it names no project this way.
     *
     * @return list<string>
     */
    public static function named(?string $operation): array
    {
        return $operation === null ? [] : (self::MAP[$operation] ?? []);
    }
}
