<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SystemBuildCache',
    description: 'A BuildKit cache of one build of a public repository, which a deploy of that repository and branch imports.',
    properties: [
        new OA\Property(property: 'ref', type: 'string', example: 'buildcache/github.com/twin/gatus:master', description: 'The tag in the cache registry.'),
        new OA\Property(property: 'repository', type: 'string', example: 'github.com/twin/gatus', description: 'The repository\'s host and path, lowercased.'),
        new OA\Property(property: 'tag', type: 'string', example: 'master', description: 'The branch, plus a hash of the build for anything but a root Dockerfile.'),
    ],
    type: 'object',
)]
class SystemBuildCacheSchema {}
