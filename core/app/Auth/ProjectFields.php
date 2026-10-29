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
        'POST /projects/{username}/clone' => ['new_username'],
        'POST /projects/{username}/staging' => ['new_username'],
        'GET /tasks' => ['username'],
        self::SOURCE_INSPECT => ['source'],
    ];

    /**
     * Operations that bring the project they name into existence. Leaving that
     * name out asks the engine for one, which is a project no scope can list.
     */
    private const CREATING = [
        'POST /projects/{username}/clone',
        'POST /projects/{username}/staging',
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
        if ($operation === self::SOURCE_INSPECT && ! self::inspectsProject($request)) {
            return [];
        }

        $names = [];
        foreach (self::named($operation) as $field) {
            $value = self::givenName($request, $field);
            if ($value !== null) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * The field that should name the project this operation creates but does
     * not, or null when it creates nothing or the request names one.
     */
    public static function missingCreationName(Request $request, ?string $operation): ?string
    {
        if (! in_array($operation, self::CREATING, true)) {
            return null;
        }

        $field = self::named($operation)[0] ?? null;

        if ($field === null || self::givenName($request, $field) !== null) {
            return null;
        }

        return $field;
    }

    /** The name a request gives this field, or null when it leaves it out. */
    private static function givenName(Request $request, string $field): ?string
    {
        $value = $request->input($field);

        return is_string($value) && $value !== '' ? $value : null;
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
