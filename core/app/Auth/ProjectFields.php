<?php

namespace App\Auth;

use App\Lib\Deploy\Inspect\SourceResolver;
use App\Rules\InspectableSource;
use Illuminate\Http\Request;

/**
 * Operations that name a project in the body or the query, and the field it is
 * in -- `POST /{alice}/push {"target":"bob"}` acts on bob, not on alice.
 *
 * One map, read by both middlewares that ask about the projects a request
 * names: the token-scope check and the protected-project check.
 */
class ProjectFields
{
    private const SOURCE_INSPECT = 'POST /source/inspect';

    /** @var array<string, list<string>> */
    private const MAP = [
        'POST /projects/{username}/push' => ['target'],
        'GET /projects/{username}/push/diff' => ['target'],
        'GET /tasks' => ['username'],
        self::SOURCE_INSPECT => ['source'],
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

    /**
     * The projects one request names in those fields. An inspected source
     * names a project only when it is read as one, never as a repository.
     *
     * @return list<string>
     */
    public static function namedIn(Request $request, ?string $operation): array
    {
        if ($operation === self::SOURCE_INSPECT && !self::inspectsProject($request)) {
            return [];
        }

        $names = [];
        foreach (self::named($operation) as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    /** The inspection's own reading: a declared `type` first, else the shape of `source`. */
    private static function inspectsProject(Request $request): bool
    {
        $source = $request->input('source');
        $type = InspectableSource::declaredType($request->input('type'))
            ?? (is_string($source) ? SourceResolver::classify($source) : null);

        return $type === SourceResolver::TYPE_PROJECT;
    }
}
