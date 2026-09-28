<?php

namespace App\Lib\Deploy\Engine;

/**
 * A host build container's memory and where the figure came from, so the
 * deploy log can say which lever sets it (engine#184).
 */
final class BuildMemory
{
    /** `DEPLOY_BUILD_MEMORY`, set by the operator for the whole server. */
    public const SETTING = 'setting';

    /** Nothing set, and the project's limit is not above it: a share of the server's RAM. */
    public const SERVER = 'server';

    /** The project's memory limit, which is above the server share. */
    public const PROJECT = 'project';

    /** The project's memory limit, held down to the cap on what a project may raise it to. */
    public const PROJECT_CAPPED = 'project_capped';

    /**
     * @param string $limit as handed to the builder, which floors and re-spells it
     */
    public function __construct(
        public readonly string $limit,
        public readonly string $source = self::SETTING,
        public readonly ?int $serverShareMb = null,
        public readonly ?int $projectLimitMb = null,
    ) {
    }
}
