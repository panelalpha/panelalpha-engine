<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SecretVaultEntry',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The entry\'s id. It names the entry nowhere; pass `ref`.', example: 42),
        new OA\Property(property: 'ref', type: 'string', description: 'The reference to pass in place of the secret, and to status or delete.', example: 'vault:Kq3xT9bLmW2vR8nYp4sZ6cHd'),
        new OA\Property(property: 'type', type: 'string', description: 'The request field the reference will be passed in (free-form snake_case).', example: 'git_token'),
        new OA\Property(property: 'purpose', type: 'string', nullable: true, description: 'What the secret is for, given at create time and shown on the paste form. The secret is never readable, so this is what distinguishes two entries of the same type.', example: 'Deploy key for the shop repo'),
        new OA\Property(property: 'verify_with', type: 'object', nullable: true, description: 'What a paste is checked against before it is saved, as given at create time.', example: ['repo_url' => 'https://github.com/acme/shop']),
        new OA\Property(property: 'verification', type: 'object', nullable: true, description: 'The check a stored secret passed: `result` is `verified` (the token read `target`) or `unchecked` (the host could not be reached; `reason` says why). Null when nothing was checked. A refused token is never stored, so it never appears here.', example: ['result' => 'verified', 'target' => 'github.com/acme/shop', 'checked_at' => '2026-09-23T10:00:00+00:00']),
        new OA\Property(property: 'scope', type: 'string', enum: ['project', 'global'], description: '`project`: usable by one project only, the first one it is given to. `global`: usable by any project that names it.', example: 'project'),
        new OA\Property(property: 'project', type: 'string', nullable: true, description: 'The project a `project` entry belongs to. Null for a global entry, and for a project entry no project has used yet.', example: 'shop'),
        new OA\Property(property: 'url', type: 'string', nullable: true, description: 'The form the customer pastes the secret into (create only).', example: 'https://engine.example.com/vault/7f3a9c2b5d1e4f6a8b0c2d4e6f8a0b2c4d5e6f7a8b9c'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'filled', 'abandoned', 'expired'], description: '`abandoned`: the paste link closed before anything was pasted. `expired`: past `expires_at`; refused, and deleted within the hour.', example: 'filled'),
        new OA\Property(property: 'url_expires_in', type: 'integer', nullable: true, description: 'Seconds the paste form stays open (create only).', example: 3600),
        new OA\Property(property: 'used_count', type: 'integer', example: 1),
        new OA\Property(property: 'last_used_at', type: 'string', nullable: true, example: '2026-09-11T10:00:00+00:00'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, example: '2026-09-11T09:00:00+00:00'),
        new OA\Property(property: 'expires_at', type: 'string', nullable: true, description: 'When the secret expires and is deleted. Null: it never expires.', example: null),
        new OA\Property(property: 'url_expires_at', type: 'string', nullable: true, description: 'When the paste form closes.', example: '2026-09-11T10:00:00+00:00'),
        new OA\Property(property: 'public_key', type: 'string', description: '`deploy_key` only: the line to add to the repository as a read-only deploy key.', example: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... panelalpha-shop'),
        new OA\Property(property: 'fingerprint', type: 'string', description: '`deploy_key` only: the key\'s SHA256 fingerprint, as GitHub and GitLab list it.', example: 'SHA256:4Xz0m1VbX0c0y3oN6m4b8m1o0b2cZf1x8w2nQk3fJ1E'),
        new OA\Property(
            property: 'hosts',
            type: 'array',
            description: '`deploy_key` only: the git servers a clone with this key can verify. `published`: keys that ship with the engine; `scanned`: read from `host` when the key was made.',
            items: new OA\Items(properties: [
                new OA\Property(property: 'host', type: 'string', example: 'github.com'),
                new OA\Property(property: 'pinned', type: 'string', enum: ['published', 'scanned']),
                new OA\Property(property: 'fingerprints', type: 'array', items: new OA\Items(type: 'string')),
            ], type: 'object'),
        ),
    ],
    type: 'object', )]
class SecretVaultEntrySchema {}
