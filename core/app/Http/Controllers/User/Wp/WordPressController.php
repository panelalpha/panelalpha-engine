<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Controllers\Controller;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * One call on a project\'s WordPress install, answered as {"data": ...}. A
 * WordPress failure keeps its own status; anything else is a 422 on the
 * controller's field.
 */
abstract class WordPressController extends Controller
{
    /** The field a failure outside the WordPress module is reported under. */
    abstract protected function failureField(): string;

    /** @param callable(WordPress, array<string, mixed>): mixed $call */
    protected function respond(string $username, FormRequest $request, callable $call): JsonResponse
    {
        $validated = $request->validated();
        $wordpress = WordPress::for(
            $this->projectOr404($username),
            self::optional($validated['path'] ?? null),
            service: self::optional($validated['service'] ?? null),
            skipPlugins: filter_var($validated['skip_plugins'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        try {
            return new JsonResponse(['data' => $call($wordpress, $validated)]);
        } catch (WordPressException $e) {
            abort(new JsonResponse(['message' => $e->getMessage()], $e->httpStatus));
        } catch (\Exception $e) {
            throw ValidationException::withMessages([$this->failureField() => $e->getMessage()]);
        }
    }

    /** A non-empty string, or null. */
    protected static function optional(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
