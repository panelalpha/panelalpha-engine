<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectSource',
    description: 'Where a project\'s code comes from. `type` decides which other keys apply; a key of another '
        .'type is refused (`unknown_field`). none: an empty project (a container project waits for files). '
        .'git: `url`, `branch`, `auth`. archive: exactly one of `upload_id`, `url` (or `path`, on PUT '
        .'/projects/{username}/source only). project: `from`, `copy_databases`. wordpress: `title`, `admin`, '
        .'`locale`, `version`.',
    required: ['type'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['none', 'git', 'archive', 'project', 'wordpress'], example: 'git',
            description: 'none: empty project. git: clone a repository (container). archive: deploy a .zip/.tar.gz (container). '
                .'project: copy another project. wordpress: a php_hosting project with a fresh WordPress.'),
        new OA\Property(property: 'url', type: 'string', example: 'https://github.com/acme/shop',
            description: 'git: the repository. HTTPS (github.com/owner/repo is completed to https://), or an SSH remote '
                .'(git@host:owner/repo.git, ssh://git@host:port/owner/repo.git), which needs auth type deploy_key. '
                .'archive: an http(s) URL of a .zip or .tar.gz the deploy downloads (format read from the file itself).'),
        new OA\Property(property: 'branch', type: 'string', example: 'main', description: 'git: branch or tag; omitted, the remote\'s default branch. Checked against the remote before anything is created.'),
        new OA\Property(
            property: 'auth',
            description: 'git: how the clone authenticates; omitted, anonymously. token: an HTTPS token (or a vault:<key> of '
                .'type git_token). deploy_key: an SSH key from POST /vault/deploy-keys, as vault:<key>; its public half must '
                .'be on the repository before the create.',
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['none', 'token', 'deploy_key'], example: 'token'),
                new OA\Property(property: 'token', type: 'string', example: 'vault:Kq3xT9bLmW2vR8nYp4sZ6cHd', description: 'type token: the token, or a vault:<key> of type git_token. Never stored in a task or log.'),
                new OA\Property(property: 'key', type: 'string', example: 'vault:Pd7mW3qZ9xL2vB8nR4tY6cHk', description: 'type deploy_key: the vault:<key> POST /vault/deploy-keys returned.'),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'upload_id', type: 'string', example: 'upl_01jaxk3m5v9w2c8e4r7t6y1b0n', description: 'archive: an id from POST /uploads. The create claims it and moves it into the project.'),
        new OA\Property(property: 'path', type: 'string', example: '/project/app.zip', description: 'archive, PUT /projects/{username}/source only: an archive already in the project home.'),
        new OA\Property(property: 'from', type: 'string', example: 'shop', description: 'project: the project to copy. The copy takes its limits, settings, template and files; only `name` and `domain.name` may be given.'),
        new OA\Property(property: 'copy_databases', type: 'boolean', example: true, description: 'project: copy the application\'s databases too. Default true.'),
        new OA\Property(property: 'title', type: 'string', example: 'My shop', description: 'wordpress: the site title, at most 255 characters.'),
        new OA\Property(
            property: 'admin',
            description: 'wordpress: the first administrator.',
            properties: [
                new OA\Property(property: 'user', type: 'string', example: 'admin', description: '1-60 letters, digits, spaces or _ . @ -'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'owner@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'a long passphrase', description: 'At least 8 characters. Never stored by the engine or shown in a task.'),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'locale', type: 'string', example: 'en_US', description: 'wordpress: core language, e.g. en_US, de_DE; default en_US.'),
        new OA\Property(property: 'version', type: 'string', example: 'latest', description: 'wordpress: latest (default) or a version such as 6.6.2.'),
    ],
    type: 'object',
)]
class ProjectSourceSchema {}
