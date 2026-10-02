<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // Delivered to the app byte for byte, as MCP already does: a secret may start or end with whitespace.
        'env_vars.*',
        // files/put-contents writes this as the file: a lockfile's final newline matters.
        'contents',
    ];
}
