<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectDomain',
    description: 'The project\'s main domain. Omitted entirely, the engine picks the best public name it can.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'shop.example.com',
            description: 'A domain of the caller\'s own (usually with tunnel none), or a label under panelalpha.online. Omitted: '
                .'<name>.<sites_base_domain>, a free panelalpha.online label, or <name>.<cert_domain>, in that order.'),
        new OA\Property(property: 'tunnel', type: 'string', enum: ['panelalpha', 'none'], nullable: true,
            description: 'How the domain reaches this host. Omitted, the engine setting default_tunnel decides. panelalpha: '
                .'a PanelAlpha Online tunnel, so `name`, if given, must be a panelalpha.online label. none: nothing is registered.'),
        new OA\Property(property: 'redirect_url', type: 'string', nullable: true, example: 'https://example.com/', description: 'Redirect the domain here instead of serving the project.'),
    ],
    type: 'object',
)]
class ProjectDomainSchema {}
