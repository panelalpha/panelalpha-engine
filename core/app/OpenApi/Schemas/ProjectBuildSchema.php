<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectBuild',
    description: 'How a container project builds. Not taken by a php_hosting project or a copy; an empty project takes `env_vars` only.',
    properties: [
        new OA\Property(property: 'env_vars', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'),
            description: 'KEY=value applied to the app\'s .env and container on every deploy, outranking what the platform generates. '
                .'A value may be a vault:<key> of type env_vars. At most 200; an empty value is not stored.',
            example: ['APP_ENV' => 'production']),
        new OA\Property(property: 'recipe', type: 'string', nullable: true, example: 'php',
            description: 'Recipe id to deploy with instead of the detected one, from POST /source/inspect application.candidates. This deploy only.'),
        new OA\Property(property: 'stages', type: 'object', nullable: true,
            description: 'Commands this deploy runs, per stage (precheck, prepare, build, install, upgrade, start): {stage: [{id, run, optional, serve, timeout, workdir, role}]}; [] skips a stage. This deploy only.'),
    ],
    type: 'object',
)]
class ProjectBuildSchema {}
