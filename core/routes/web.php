<?php

use App\Http\Controllers\Web;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return new Response(null, 204);
});

// Unauthenticated, so a development convenience only: never on an install.
if (app()->environment('local') && config('app.debug')) {
    Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);
}

/*
 * The vault form: where a customer pastes a secret an API caller must not
 * relay. Unauthenticated by design -- the 48-char token in the URL is the
 * capability, short-lived and scoped to one entry, the same trust model as
 * the app SSO token route. Throttled on both verbs: a shared or guessed URL
 * cannot be brute-forced, and a repeated submit cannot flood the engine.
 * Never register this under /api: no bearer auth belongs here.
 */
Route::get('/vault/{token}', [Web\VaultFormController::class, 'show'])
    ->middleware('throttle:20,10');
Route::post('/vault/{token}', [Web\VaultFormController::class, 'store'])
    ->middleware('throttle:10,10');
