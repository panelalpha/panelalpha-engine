<?php

namespace App\Integrations\Monitoring;

use App\Lib\Apis\PanelAlpha;

/**
 * PanelAlpha Monitoring: where an engine reports what it did.
 *
 * Metrics and telemetry — deploy reports, recovered signals, bug reports —
 * all of which are observations about this installation. Separate from the
 * Connect ({@see \App\Integrations\Tunnels\PanelAlphaConnect}): Connect is an
 * *integration* the engine calls to get something done (a WithoutDNS name
 * created, a licence resolved), and this is a sink the engine talks at. They
 * are two services, deployed apart, reachable apart, and an install may
 * reasonably have one and not the other.
 *
 * They used to be one variable, on the theory that "everything an engine
 * sends goes to the same host". That was wrong about the deployment and it
 * cost the telemetry a release: reports were addressed to Connect, which does
 * not serve the ingest and answers 405.
 *
 * Emptying the variable is a supported answer, not a misconfiguration: it
 * means "report nowhere". Building a bare path out of it would post the
 * engine's telemetry at whatever host a relative URL happened to resolve to,
 * so an unset value yields an empty string and every caller is expected to
 * check for one.
 */
final class PanelAlphaMonitoring
{
    /** The configured host without its trailing slash, or '' when unset. */
    public static function base(): string
    {
        return rtrim(trim((string) config('monitoring.url', '')), '/');
    }

    /** `{monitoring}/{path}`, or '' when nothing is configured. */
    public static function url(string $path): string
    {
        return PanelAlpha::url(self::base(), $path);
    }
}
