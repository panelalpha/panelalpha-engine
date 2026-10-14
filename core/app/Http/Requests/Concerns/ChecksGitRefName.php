<?php

namespace App\Http\Requests\Concerns;

use App\System\Project\Git\Ref as GitRef;
use Illuminate\Validation\Validator;

trait ChecksGitRefName
{
    /** Refuses a non-empty string in $field that git would not take as a ref name. */
    protected function checkGitRefName(Validator $validator, string $field): void
    {
        $validator->after(function (Validator $validator) use ($field): void {
            $name = $validator->getData()[$field] ?? null;
            if (is_string($name) && $name !== '' && !GitRef::isValidName($name)) {
                $validator->errors()->add($field, 'Invalid git ref name.');
            }
        });
    }
}
