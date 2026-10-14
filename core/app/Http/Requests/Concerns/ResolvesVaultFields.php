<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;
use App\Models\User;

/**
 * Read a `vault:<id>` in place of a secret field, typed by the field's own name
 * as `git_token` and `env_vars` are. An unusable reference is a 422 naming the field.
 */
trait ResolvesVaultFields
{
    /**
     * Call from prepareForValidation(), so the rules (a min:8, say) see the
     * secret rather than the reference.
     *
     * @param list<string> $fields
     */
    protected function resolveVaultFields(array $fields): void
    {
        $references = array_filter($fields, fn (string $field): bool => $this->has($field) && self::holdsReference($this->input($field)));
        if ($references === []) {
            return;
        }

        $project = $this->existingProject();
        foreach ($references as $field) {
            $this->merge([$field => RequestVault::resolve($field, $this->input($field), $project)]);
        }
    }

    /** Whether a field's value, or any item of an array field, is a `vault:<id>`. */
    private static function holdsReference(mixed $value): bool
    {
        $values = is_array($value) ? $value : [$value];

        return array_filter($values, RequestVault::isReference(...)) !== [];
    }

    /**
     * The route's project when it exists. A name that does not is read as no
     * project, so a typo cannot claim an unassigned entry before the 404.
     */
    private function existingProject(): ?string
    {
        $project = $this->route('username');
        if (!is_string($project) || $project === '') {
            return null;
        }

        return User::existsByUsername($project) ? $project : null;
    }
}
