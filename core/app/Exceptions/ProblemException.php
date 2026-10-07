<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A 422 that a program can act on, not only display.
 *
 * Laravel's `errors` map is `field => [sentence]`, which is the right shape
 * for a form and the wrong one for a client deciding what to do next: an
 * assistant reading "This project needs PHP 8.3, but it was built with PHP
 * 8.1" has to parse English to learn it should pin an image. Worse, a deploy
 * failure had no field at all -- `withMessages([$message])` keys on 0, so the
 * response said `errors: {"0": [...]}`: no field, no code, no stage.
 *
 * This keeps `errors` exactly as it was, so the panel and every existing
 * client are untouched, and adds `problems` beside it: one entry per thing
 * that is wrong, each with the field it belongs to, a stable `code`, the same
 * sentence, and whatever else the caller needs to act -- the `stage` a deploy
 * died in, the `deploy_log_offset` to read from.
 *
 * It extends ValidationException rather than replacing it so that
 * `catch (ValidationException)` anywhere upstream still behaves.
 */
class ProblemException extends ValidationException
{
    /**
     * The problems, in the order they were reported.
     *
     * @var list<array<string, mixed>>
     */
    public array $problems = [];

    /**
     * @param list<array{field: string, code: string, message: string, ...}> $problems
     */
    public static function of(array $problems): self
    {
        $validator = Validator::make([], []);
        foreach ($problems as $problem) {
            $validator->errors()->add($problem['field'], $problem['message']);
        }

        $e = new self($validator);
        $e->problems = array_values($problems);

        return $e;
    }

    /**
     * One problem, for the many callers that only ever have one.
     *
     * @param array<string, mixed> $extra
     */
    public static function one(string $field, string $code, string $message, array $extra = []): self
    {
        return self::of([['field' => $field, 'code' => $code, 'message' => $message] + $extra]);
    }

    /**
     * A failed deploy: the stage it died in, and where to start reading its
     * log for the rest of the story.
     */
    public static function deploy(string $code, string $message, ?string $stage): self
    {
        return self::one('deploy', $code, $message, array_filter([
            'stage' => $stage,
            'deploy_log_offset' => 0,
        ], static fn (mixed $v): bool => $v !== null));
    }
}
