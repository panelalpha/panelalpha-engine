<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Finding',
    description: 'Something a deploy or an inspection found. `message` names no surface; the facts beside `code` and '
        .'`message` depend on the code (`certificate_refused` has `domain` and `certificate`). `hints` lists each action '
        .'that resolves it, once per surface that can do it: an action with no `rest` hint is for an operator on the host.',
    required: ['code', 'message', 'hints'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: 'certificate_refused', description: 'Stable: branch on this, not on `message`. `unclassified` is a warning recorded before warnings had a code.'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'hints', type: 'array', items: new OA\Items(ref: '#/components/schemas/Hint')),
    ],
    type: 'object',
    additionalProperties: true,
)]
#[OA\Schema(
    schema: 'Hint',
    description: 'One form of an action that resolves a finding. `rest`: `method`, the `path` template with its '
        .'`path_params`, and `body` (or `query` for GET). `mcp`: the `tool` and its `arguments`. `cli`: the `command` for an '
        .'operator on the host. A null value is one the caller has to supply.',
    required: ['type', 'action'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['rest', 'mcp', 'cli']),
        new OA\Property(property: 'action', type: 'string', example: 'request_certificate', description: 'The same for every form of one action.'),
        new OA\Property(property: 'method', type: 'string', example: 'POST'),
        new OA\Property(property: 'path', type: 'string', example: '/projects/{username}/domains/{domain}/request-ssl-cert'),
        new OA\Property(property: 'path_params', type: 'object'),
        new OA\Property(property: 'body', type: 'object'),
        new OA\Property(property: 'query', type: 'object'),
        new OA\Property(property: 'tool', type: 'string', example: 'ssl_cert_request'),
        new OA\Property(property: 'arguments', type: 'object'),
        new OA\Property(property: 'command', type: 'string', example: 'pae ssl:project-cert:request shop.example.com'),
    ],
    type: 'object',
)]
class FindingSchema {}
