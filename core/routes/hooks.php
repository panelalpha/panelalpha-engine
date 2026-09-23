<?php

use App\Http\Controllers\Web\HookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Deploy Hook endpoint
|--------------------------------------------------------------------------
|
| Loaded by the RouteServiceProvider with no middleware group at all: not
| `api` (no bearer token can be sent by a git host), not `web` (no session,
| no cookies, no CSRF). What guards it is the HMAC signature HookReceiver
| checks against the raw body and the unguessable id in the path.
|
| POST only. The id is hex; anything else cannot be a hook and never reaches
| the database.
*/

Route::post('/hooks/{publicId}', [HookController::class, 'receive'])
    ->where('publicId', '[A-Za-z0-9]{16,64}');
