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

    /** The project named in the route, or a JSON 404 "Project not found". */
    protected function projectOr404(string $username): User
    {
        return User::findByUsername($username) ?? abort(new JsonResponse(['message' => 'Project not found'], 404));
    }
}
