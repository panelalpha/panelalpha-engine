<?php

/*
|--------------------------------------------------------------------------
| PanelAlpha Monitoring
|--------------------------------------------------------------------------
|
| Where an engine reports what it did: metrics, and the deploy telemetry,
| recovered signals and bug reports described in docs/08-telemetry/what-is-collected.md. All of it
| arrives on one shared event route, told apart by the event `type`.
|
| Deliberately not `config('connect.url')`. Connect is an integration the engine
| *calls* — WithoutDNS names, licensing — and this is a sink it reports to.
| Two services, deployed separately, and an install can have one without the
| other. Collapsing them into one variable is a mistake this codebase has
| already made once: telemetry was addressed to Connect, which does not serve
| the ingest and answers 405 to every report.
|
| `https`, since the day the host started serving it. It was `http` for as
| long as monitoring.panelalpha.com refused connections on 443, because an
| https default would have held every report in the spool forever while
| looking correctly configured. It now answers on 443 and redirects 80 there
| with a 301 — which is worse than either: a redirected POST arrives as a
| GET, the ingest answers 405, and the shipper reads that as "no route here"
| and keeps retrying a batch nothing will ever accept. Reports are redacted
| before they leave, but redacted is not encrypted, and a bug report may
| carry a contact address somebody typed.
|
| Empty is a supported answer meaning "report nowhere": App\Integrations\
| Monitoring\PanelAlphaMonitoring builds no URL from it, and each caller holds
| what it would have sent rather than posting at a relative path.
|
*/

return [

    'url' => (string) env('PANELALPHA_MONITORING', 'https://monitoring.panelalpha.com'),

];
