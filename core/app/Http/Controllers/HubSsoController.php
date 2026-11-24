<?php

namespace App\Http\Controllers;

use App\Http\Requests\HubSsoLoginRequest;
use App\Lib\Hub\HubAdminLogin;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * POST /panelalpha-sso/hub on an app's own domain, routed here by its vhost: the Hub's login ticket in, the app's
 * one-time admin login out. Every refusal is the same page, so none says which check failed.
 */
class HubSsoController extends Controller
{
    public const REFUSED = 'This login link is not valid any more. Open it again from PanelAlpha.';

    public function login(HubSsoLoginRequest $request, string $username): BaseResponse
    {
        $url = HubAdminLogin::link($username, $request->getHost(), $request->ticket());
        if ($url === null) {
            return self::refused();
        }

        return redirect()->away($url, 303)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public static function refused(): Response
    {
        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Login link not valid</title></head>'
            .'<body><p>'.self::REFUSED.'</p></body></html>';

        return new Response($page, 403, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
