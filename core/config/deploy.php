<?php

return [
    /*
     * The Docker network host build containers run on (engine#246). The engine
     * creates it and scripts/build-network-firewall.sh lets it reach the
     * internet only -- not the engine API, the host, its private network or
     * 169.254.169.254. Empty runs builds on Docker's default bridge as before,
     * for a host whose registries or proxy sit on a private address.
     */
    'build_network' => env('DEPLOY_BUILD_NETWORK', 'panelalpha-build'),

    /*
     * Memory a host build container may use, or empty to size it from the host.
     *
     * A build is a host resource, not an account one: it runs in a throwaway
     * container on the engine, before the project's own container exists, and
     * several accounts can be building at once. So the ceiling belongs to the
     * operator who knows the machine, not to a hosting plan -- but a fixed
     * 2g is the wrong ceiling on a machine that has more, and that is not a
     * hypothetical: Chamilo 2.x's Encore build OOMs inside 2g on a 15 GB host
     * and succeeds at a 3584 MB heap, which our own 70% rule reaches in a
     * ~5 GB container.
     *
     * Empty (the default, and what an install that never mentioned this key
     * gets) means "a third of this host's MemTotal, floored at 2g and capped
     * at 8g" -- see {@see \App\Lib\Deploy\Compose\ServiceLimits::hostBuildMemoryMb()}.
     * Setting it is an override: `2g` pins every build to 2g, `8g` gives every
     * build 8g, and it is never sized below the floor.
     *
     * Accepts a Docker size string: 512m, 2g, 4096m. An unparseable value
     * falls back to the host-derived default rather than failing every deploy.
     */
    'build_memory' => env('DEPLOY_BUILD_MEMORY', ''),

    /*
     * Seconds a streamed deploy step may go without printing anything before
     * it is killed and the deploy fails as `build-stalled`. Deploys share one
     * worker, so a hung step blocks every account. 0 turns the watchdog off.
     */
    'step_idle_timeout' => (int) env('DEPLOY_STEP_IDLE_TIMEOUT', 900),
];
