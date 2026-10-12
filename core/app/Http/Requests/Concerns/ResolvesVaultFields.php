<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;

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
        $project = $this->route('username');

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => RequestVault::resolve(
                        $field,
                        $this->input($field),
                        is_string($project) && $project !== '' ? $project : null,
                    ),
                ]);
            }
        }
    }
}
