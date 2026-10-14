<?php

namespace App\Http\Requests\Concerns;

/**
 * The `name` of a MySQL database or user: one grammar for create and rename,
 * and messages that state it.
 */
trait ValidatesMysqlName
{
    private function mysqlNameRule(int $maxLength): string
    {
        return 'string|required|regex:/^[A-Za-z_-][A-Za-z0-9_-]*$/D|min:1|max:' . $maxLength;
    }

    /**
     * @return array<string, string>
     */
    private function mysqlNameMessages(string $label, int $maxLength): array
    {
        return [
            'name.max' => "{$label} cannot exceed {$maxLength} characters (MySQL limit).",
            'name.regex' => "{$label} must start with a letter, underscore or hyphen and can only contain letters, numbers, underscores and hyphens.",
        ];
    }
}
