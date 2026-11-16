<?php

namespace App\Lib\Host;

/**
 * What this engine's API can do that an older one cannot, for a client that
 * talks to engines of several versions (GET /system/info `features`).
 */
final class ApiFeatures
{
    /**
     * POST /projects takes the typed body with `source` and GET returns it; PUT /projects/{username}/source,
     * POST /uploads and POST /vault/deploy-keys exist.
     */
    public const PROJECT_SOURCE = 'project_source';

    /** @var list<string> */
    public const ALL = [self::PROJECT_SOURCE];
}
