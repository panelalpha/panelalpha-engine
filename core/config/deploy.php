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
     * Empty (the default) gives it 8g. Set or not, it never gets more than half
     * the host's RAM, nor the host's RAM less engine_memory below (engine#295).
     * Builds run one at a time (HostBuildSlot).
     *
     * Accepts a Docker size string: 512m, 2g, 4096m. An unparseable value
     * falls back to the host-derived default rather than failing every deploy.
     */
    'build_memory' => env('DEPLOY_BUILD_MEMORY', ''),

    /*
     * MB of the host's RAM kept for the engine. No project may have a
     * memory_limit above the host's RAM less this. Empty: 512.
     */
    'engine_memory' => env('DEPLOY_ENGINE_MEMORY', ''),

    /*
     * The memory_limit a project is created with when none is given, in MB.
     * Empty: the most a project may have, the host's RAM less engine_memory.
     */
    'project_memory_default' => env('DEPLOY_PROJECT_MEMORY_DEFAULT', ''),

    /*
     * Seconds a streamed deploy step may go without printing anything before
     * it is killed and the deploy fails as `build-stalled`. Deploys share one
     * worker, so a hung step blocks every account. 0 turns the watchdog off.
     */
    'step_idle_timeout' => (int) env('DEPLOY_STEP_IDLE_TIMEOUT', 900),

    /*
     * Free disk the engine host must have for a deploy to start, checked on
     * the Docker root and /home. Below it the deploy is refused with that
     * reason instead of failing later on ENOSPC. A size (3G, 512M); 0 or `off`
     * turns the check off. See App\System\Project\Dind\HostDiskGuard.
     */
    'host_min_free' => env('DEPLOY_HOST_MIN_FREE', '3G'),

    /*
     * `system:image:prune` (once a day, retried hourly past a deploy) removes deploy base images from the host
     * that were not pulled, built or named by a deploy for this long. The
     * prewarm catalogue and images a container uses are always kept. A
     * duration (3d, 72h); `off` stops the image half of the prune.
     */
    'host_image_retention' => env('DEPLOY_HOST_IMAGE_RETENTION', '3d'),

    /*
     * The same prune drops host build cache unused for this long; `off` keeps
     * it for the weekly `system:image:prewarm` to clear.
     */
    'host_build_cache_retention' => env('DEPLOY_HOST_BUILD_CACHE_RETENTION', '24h'),
];
