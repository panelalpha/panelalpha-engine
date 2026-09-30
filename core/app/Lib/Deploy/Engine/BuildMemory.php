<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Host\HostMemory;

/**
 * A host build container's memory and where the figure came from, so the
 * deploy log can say which lever sets it (engine#184).
 */
final class BuildMemory
{
    /** `DEPLOY_BUILD_MEMORY`, set by the operator for the whole server. */
    public const SETTING = 'setting';

    /** `DEPLOY_BUILD_MEMORY` above the build ceiling, held to it. */
    public const SETTING_CAPPED = 'setting_capped';

    /** Nothing set: 8 GB, held to half the server's RAM and its RAM less DEPLOY_ENGINE_MEMORY (engine#295). */
    public const HOST = 'host';

    /**
     * @param string $limit as handed to the builder, which re-spells it
     */
    public function __construct(
        public readonly string $limit,
        public readonly string $source = self::SETTING,
        public readonly ?HostMemory $host = null,
    ) {
    }
}
