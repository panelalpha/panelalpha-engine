<?php

/*
|--------------------------------------------------------------------------
| PanelAlpha Connect
|--------------------------------------------------------------------------
|
| The host an engine asks things of. It has its own file rather than another
| line in `env.php` because it is a service the engine talks to, not a value
| the environment happens to carry: the URL is the default here, and the
| variable only overrides it.
|
| Connect is an integration the engine *calls*: WithoutDNS names, licensing,
| the things it needs an answer from. What the engine *reports* -- metrics and
| telemetry -- goes somewhere else entirely, to `config('monitoring.url')`.
|
| Those two were one variable here for a release, on the theory that
| everything an engine sends goes to one host. They are two services deployed
| apart, and the theory cost the telemetry a release: every report was
| addressed to Connect, which does not serve the ingest and answers 405.
| Anything new that reports rather than asks belongs on monitoring, not here.
|
| Empty is a supported answer meaning "no Connect": App\Integrations\Tunnels\
| PanelAlphaConnect builds no URL from it, and each caller is expected to say so
| rather than calling a relative path.
|
| There is one Connect and it is `connect.panelalpha.com`. The variable exists so an
| install can be pointed at a replacement, not so a second environment can be
| kept alongside it: an engine talking to a staging Connect allocates real names
| and verifies real licences against records that are not the ones anybody
| looks at, and the only sign is a hostname in a config file.
|
*/

return [

    'url' => (string) env('PANELALPHA_CONNECT', 'https://connect.panelalpha.com'),

];
