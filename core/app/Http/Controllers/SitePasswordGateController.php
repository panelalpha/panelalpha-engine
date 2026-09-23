<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unauthenticated endpoints nginx auth_request / the custom password form hit.
 * Mounted under /api/internal/site-password with auth:api removed.
 */
class SitePasswordGateController extends Controller
{
    /**
     * auth_request probe: 200 if Basic password or session cookie is valid.
     */
    public function check(Request $request, string $username): Response
    {
        $user = User::findByUsername($username);
        if (!$user || !SitePasswordProtection::isEnabled($user)) {
            return new Response('', 200);
        }

        if (SitePasswordProtection::cookieIsValid($user, $request->cookie(SitePasswordProtection::COOKIE_NAME))) {
            return new Response('', 200);
        }

        $password = SitePasswordProtection::passwordFromBasicHeader($request->header('Authorization'));
        if ($password !== null && SitePasswordProtection::verifyPassword($user, $password)) {
            return new Response('', 200);
        }

        return new Response('', 401);
    }

    /**
     * Custom-mode failure page (password form only).
     */
    public function gate(Request $request, string $username): Response
    {
        $user = User::findByUsername($username);
        if (!$user || !SitePasswordProtection::isEnabled($user)) {
            return new Response('Not found', 404);
        }

        $error = $request->query('error') === '1';
        $html = $this->formHtml($error);

        return new Response($html, 401, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Custom-mode form POST: set session cookie and redirect to original URI.
     */
    public function login(Request $request, string $username): Response|RedirectResponse
    {
        $user = User::findByUsername($username);
        if (!$user || !SitePasswordProtection::isEnabled($user)) {
            return new Response('Not found', 404);
        }

        $password = (string) $request->input('password', '');
        $redirect = (string) $request->input('redirect', '/');
        if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = '/';
        }

        if (!SitePasswordProtection::verifyPassword($user, $password)) {
            return new Response($this->formHtml(true), 401, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        $secure = $request->isSecure()
            || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        $cookie = cookie(
            SitePasswordProtection::COOKIE_NAME,
            SitePasswordProtection::cookieValue($user),
            0, // session cookie — mirrors browser Basic Auth cache lifetime
            '/',
            null,
            $secure,
            true,
            false,
            'Lax'
        );

        return redirect($redirect)->withCookie($cookie);
    }

    private function formHtml(bool $error): string
    {
        $errorHtml = $error
            ? '<p class="error">Incorrect password.</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password required</title>
    <style>
        body { margin: 0; font-family: 'Open Sans', system-ui, sans-serif; background: #fff; color: #49495f; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { max-width: 420px; width: 100%; text-align: center; }
        h1 { font-size: 28px; margin: 0 0 12px; }
        p { margin: 0 0 20px; line-height: 1.4; }
        .error { color: #b42318; }
        input[type=password] {
            width: 100%; box-sizing: border-box; padding: 12px 14px;
            border: 1px solid #cfd3e0; border-radius: 4px; font-size: 16px;
        }
        button {
            margin-top: 14px; width: 100%; padding: 12px 14px; border: 0; border-radius: 4px;
            background: #49495f; color: #fff; font-size: 16px; cursor: pointer;
        }
        button:hover { background: #3a3a4d; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Password required</h1>
            <p>This site is password protected.</p>
            {$errorHtml}
            <form method="post" action="/_pa_site_password" autocomplete="current-password">
                <input type="hidden" name="redirect" id="pa-redirect" value="/">
                <input type="password" name="password" placeholder="Password" autofocus required>
                <button type="submit">Continue</button>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('pa-redirect').value = window.location.pathname + window.location.search + window.location.hash;
    </script>
</body>
</html>
HTML;
    }
}
