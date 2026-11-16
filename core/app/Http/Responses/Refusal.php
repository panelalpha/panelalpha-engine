<?php

namespace App\Http\Responses;

use App\Lib\Findings\Hints\FindingPresenter;
use Illuminate\Http\JsonResponse;

/**
 * A refusal answered outside validation, in the shape a 422 has: the sentence in `message`, and `problems` with the
 * code a client branches on and the hints that act on it.
 */
final class Refusal
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $headers
     */
    public static function json(int $status, string $field, string $code, string $message, ?string $username, array $facts = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(self::body($field, $code, $message, $username, $facts), $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{message: string, code: string, problems: list<array<string, mixed>>}
     */
    public static function body(string $field, string $code, string $message, ?string $username, array $facts = []): array
    {
        return [
            'message' => $message,
            'code' => $code,
            'problems' => FindingPresenter::problems([['field' => $field, 'code' => $code, 'message' => $message] + $facts], $username),
        ];
    }
}
