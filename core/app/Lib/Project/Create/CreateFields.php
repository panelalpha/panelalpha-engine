<?php

namespace App\Lib\Project\Create;

use App\Exceptions\ProblemException;
use Illuminate\Validation\ValidationException;

/**
 * Problems about a typed create, renamed from the flat field the create checked to the field the caller sent.
 */
final class CreateFields
{
    /** Flat fields the typed body spells differently, besides {@see CreateShape::LEGACY_FIELDS}. */
    private const RENAMED = [
        'domain' => 'domain.name',
        'git_deploy_key' => 'source.auth.key',
        'zip_path' => 'source.path',
        'new_username' => 'name',
    ];

    /** Flat fields whose sub-keys keep their names under a typed parent. */
    private const PREFIXES = [
        'env_vars.' => 'build.env_vars.',
        'stages.' => 'build.stages.',
    ];

    public static function typed(string $field): string
    {
        $exact = self::RENAMED[$field] ?? CreateShape::LEGACY_FIELDS[$field] ?? null;
        if ($exact !== null) {
            return $exact;
        }
        foreach (self::PREFIXES as $flat => $typed) {
            if (str_starts_with($field, $flat)) {
                return $typed.substr($field, strlen($flat));
            }
        }

        return $field;
    }

    /**
     * The same refusal with every field renamed, and the same status.
     */
    public static function renamed(ValidationException $e): ValidationException
    {
        $renamed = $e instanceof ProblemException
            ? ProblemException::of(array_map(self::renamedProblem(...), $e->problems))
            : ValidationException::withMessages(self::renamedErrors($e->errors()));
        $renamed->status = $e->status;

        return $renamed;
    }

    /**
     * Run $work, answering its refusals with typed field names.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws ValidationException
     */
    public static function answering(callable $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $e) {
            throw self::renamed($e);
        }
    }

    /**
     * @param  array<string, mixed>  $problem
     * @return array{field: string, code: string, message: string}
     */
    private static function renamedProblem(array $problem): array
    {
        $problem['field'] = self::typed((string) ($problem['field'] ?? ''));

        return $problem;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private static function renamedErrors(array $errors): array
    {
        $renamed = [];
        foreach ($errors as $field => $messages) {
            $renamed[self::typed((string) $field)] = $messages;
        }

        return $renamed;
    }
}
