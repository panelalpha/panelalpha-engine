<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Read a `vault:<key>` in place of a secret field, typed by the field's own name
 * as `git_token` and `env_vars` are. An unusable reference is a 422 naming the field.
 */
trait ResolvesVaultFields
{
    /**
     * Call from prepareForValidation(), so the rules (a min:8, say) see the
     * secret rather than the reference.
     *
     * @param  list<string>  $fields
     */
    protected function resolveVaultFields(array $fields): void
    {
        $references = array_filter($fields, fn (string $field): bool => $this->has($field) && self::holdsReference($this->input($field)));
        if ($references === []) {
            return;
        }

        $project = $this->routeProjectOr404();
        foreach ($references as $field) {
            $this->merge([$field => RequestVault::resolve($field, $this->input($field), $project)]);
        }
    }

    /** Whether a field's value, or any item of an array field, is a `vault:<key>`. */
    private static function holdsReference(mixed $value): bool
    {
        $values = is_array($value) ? $value : [$value];

        return array_filter($values, RequestVault::isReference(...)) !== [];
    }

    /**
     * The route's project. A name that does not exist is the route's 404 before any
     * reference is read, so a typo neither claims an entry nor counts as a use of it.
     */
    private function routeProjectOr404(): ?string
    {
        $project = $this->route('username');
        if (! is_string($project) || $project === '') {
            return null;
        }
        if (! User::existsByUsername($project)) {
            abort(new JsonResponse(['message' => 'Project not found'], 404));
        }

        return $project;
    }
}
