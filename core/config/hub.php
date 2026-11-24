<?php

/*
|--------------------------------------------------------------------------
| PanelAlpha Hub
|--------------------------------------------------------------------------
|
| The account portal `pae hub:connect` connects this engine to. After the
| connect ceremony the engine reports to it every minute, signed with the Hub
| secret, and the Hub never calls the engine.
|
| Deliberately its own setting, not `config('connect.url')`: that one is the
| WithoutDNS service, and moving one must not move the other.
|
| https only. App\Lib\Hub\HubUrl refuses anything else, except
| http://localhost or http://127.0.0.1 with APP_ENV=local.
|
*/

return [

    'url' => (string) env('PANELALPHA_HUB', 'https://hub.panelalpha.com'),

];
