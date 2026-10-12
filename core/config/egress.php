<?php

return [
    /*
     * Whether the engine may fetch from RFC1918 and IPv6 unique-local
     * addresses. Off by default: an internal address is an internal service,
     * not a source. Turn it on for an operator whose Git server or file source
     * lives on the private network.
     */
    'allow_private' => filter_var(env('EGRESS_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Host names the internal-address check does not apply to, comma-separated
     * (`EGRESS_ALLOW_HOSTS=git.internal,files.internal`). A name, not an
     * address: a literal address is never bypassed. Loopback, link-local,
     * multicast and the reserved ranges are still refused for these hosts.
     *
     * @var list<string>
     */
    'allow_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('EGRESS_ALLOW_HOSTS', ''))
    ), static fn (string $host): bool => $host !== '')),
];
