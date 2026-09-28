<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /** The project named in the route, or a JSON 404 carrying this endpoint's own message. */
    protected function projectOr404(string $username, string $message = 'User not found'): User
    {
        return User::findByUsername($username) ?? abort(new JsonResponse(['message' => $message], 404));
    }

    /** The project named in the route, or the plain `abort(404)` these endpoints have always used. */
    protected function projectOrNotFound(string $username): User
    {
        return User::findByUsername($username) ?? abort(404, 'Not found');
    }
}
