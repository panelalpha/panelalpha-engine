<?php

namespace App\Rules;

use App\Lib\Vault\RequestVault;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A smarthost username, sent as itself. It is not a secret, so the vault does
 * not hold it: a read of the mail settings returns it as stored.
 */
final class EximUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (RequestVault::isReference($value)) {
            $fail('The :attribute is not a secret: send the username itself, not a `vault:` reference. '
                .'Only the passwords and API tokens take a vault reference.');
        }
    }
}
