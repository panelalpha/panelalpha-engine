<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SecretVaultEntry',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'Stable handle for this entry. Pass it as `id:<n>` to status or delete — the only way to address a request-scope entry, whose ref is shown once and never stored.', example: 42),
        new OA\Property(property: 'ref', type: 'string', nullable: true, description: 'How to name this entry again. For a request entry, the `vault:<id>` reference — only on create, never afterwards (only its hash is stored). For a global one, `global:<type>`, which does not change when its paste link is rotated.', example: 'vault:7f3a9c2b5d1e4f6a8b0c2d4e6f8a0b2c4d5e6f7a8b9c'),
        new OA\Property(property: 'type', type: 'string', description: 'The request field the reference will be passed in (free-form snake_case).', example: 'git_token'),
        new OA\Property(property: 'purpose', type: 'string', nullable: true, description: 'What the secret is for, given at create time and shown on the paste form. The secret is never readable, so this is what distinguishes two entries of the same type.', example: 'Deploy key for the shop repo'),
        new OA\Property(property: 'verify_with', type: 'object', nullable: true, description: 'What a paste is checked against before it is saved, as given at create time.', example: ['repo_url' => 'https://github.com/acme/shop']),
        new OA\Property(property: 'verification', type: 'object', nullable: true, description: 'The check a stored secret passed: `result` is `verified` (the token read `target`) or `unchecked` (the host could not be reached; `reason` says why). Null when nothing was checked. A refused token is never stored, so it never appears here.', example: ['result' => 'verified', 'target' => 'github.com/acme/shop', 'checked_at' => '2026-09-23T10:00:00+00:00']),
        new OA\Property(property: 'scope', type: 'string', enum: ['request', 'global'], description: '`request`: one secret for the calls at hand, gone when it expires. `global`: the engine\'s own secret of this type, no expiry, used by every project created without one of its own.', example: 'request'),
        new OA\Property(property: 'url', type: 'string', nullable: true, description: 'The form the customer pastes the secret into (create only).', example: 'https://engine.example.com/vault/7f3a9c2b5d1e4f6a8b0c2d4e6f8a0b2c4d5e6f7a8b9c'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'filled', 'expired'], example: 'filled'),
        new OA\Property(property: 'expires_in', type: 'integer', nullable: true, description: 'Seconds the entry lives (create only). Null for a global entry, which does not expire.', example: 3600),
        new OA\Property(property: 'url_expires_in', type: 'integer', nullable: true, description: 'Seconds the paste form stays open (create only). A global secret outlives its form.', example: 3600),
        new OA\Property(property: 'used_count', type: 'integer', example: 1),
        new OA\Property(property: 'last_used_at', type: 'string', nullable: true, example: '2026-09-11T10:00:00+00:00'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, example: '2026-09-11T09:00:00+00:00'),
        new OA\Property(property: 'expires_at', type: 'string', nullable: true, description: 'Null for a global entry.', example: '2026-09-11T10:00:00+00:00'),
        new OA\Property(property: 'url_expires_at', type: 'string', nullable: true, description: 'When the paste form closes.', example: '2026-09-11T10:00:00+00:00'),
    ],
    type: 'object',)]
class SecretVaultEntrySchema
{
}