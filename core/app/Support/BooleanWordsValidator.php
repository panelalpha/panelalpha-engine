<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Laravel's validator, where a `boolean` field also takes the words `true` and `false`, as a query string carries them.
 * The word becomes a boolean before any rule runs, so `required_unless:repair,true` and the validated input agree.
 */
class BooleanWordsValidator extends Validator
{
    private const WORDS = ['true' => true, 'false' => false];

    public function passes(): bool
    {
        foreach (array_keys($this->rules) as $attribute) {
            $this->readBooleanWord((string) $attribute);
        }

        return parent::passes();
    }

    private function readBooleanWord(string $attribute): void
    {
        $rule = $this->getRule($attribute, 'Boolean');
        $value = Arr::get($this->data, $attribute);
        if ($rule === null || ($rule[1][0] ?? null) === 'strict' || ! is_string($value)) {
            return;
        }

        $word = self::WORDS[strtolower($value)] ?? null;
        if ($word !== null) {
            Arr::set($this->data, $attribute, $word);
        }
    }
}
