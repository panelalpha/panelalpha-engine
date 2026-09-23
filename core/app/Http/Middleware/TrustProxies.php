<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Only the loopback, because nginx has already done this job.
     *
     * config/core/nginx.conf runs the realip module over both hops that exist
     * here -- the :2011 -> :80 loopback proxy and the container network -- so
     * REMOTE_ADDR reaching PHP is the real client. Trusting anything wider
     * makes Laravel process X-Forwarded-For a second time, and whoever is
     * already inside RFC1918 (a tenant's build container, for one) can then
     * name any address they like. That is what the old 10/8 + 172.16/12 +
     * 192.168/16 list allowed.
     *
     * The loopback stays listed so the chain still resolves correctly if realip
     * is ever turned off: the peer is 127.0.0.1, the header's rightmost entry
     * is the true peer nginx appended, and that is where the walk stops.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '127.0.0.1',
        '::1',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
