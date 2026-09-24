<?php

namespace App\Http\Controllers;

use App\Lib\Vault\SecretMinter;
use App\Models\SecretVaultEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * One-time browser handoff of a secret the API caller will not send.
 *
 * The flow: `create` mints an entry and returns its reference `vault:<id>`
 * plus the URL of a form the *customer* opens and pastes the secret into. The
 * agent then passes `vault:<id>` in place of the secret in any parameter that
 * accepts one (`git_token`, `cloudflare-api-token`, `env_vars` values).
 *
 * The URL carries a random token stored only as its sha-256, so a database
 * dump contains no live form links. The secret is stored encrypted and never
 * returned by any of these endpoints; `show` reports whether one is present.
 *
 * `scope` decides who may use it ({@see \App\Lib\Vault\RequestVault::get()}):
 * a `project` entry is claimed by the first project it is given to, a
 * `global` one is usable by any project that names it. Nothing is ever used
 * without being named.
 */
class SecretVaultController extends Controller
{
    #[OA\Post(
        path: '/vault/secrets',
        summary: 'Create a vault slot for a secret that will be pasted in a browser',
        description: "Mints a paste slot. Returns `ref` (`vault:<id>`, pass it where the secret would go -- "
            . "e.g. the `git_token` field of project_create) and `url` (the form the customer opens and pastes "
            . "the secret into, open for `url_expires_in` seconds). The secret expires only if `expires_in` "
            . "is given; it is then refused and deleted automatically. "
            . "**The secret never passes through the API caller** -- that is this mechanism's whole purpose, "
            . "for agents that must not relay a private-repository token through a conversation. "
            . "`scope` decides where it may be used: `project` (the default) belongs to the first project it "
            . "is given to and is refused everywhere else; `global` may be used by any project that names it. "
            . "Nothing uses a secret unasked. Give `purpose` so the entry can be recognised later: the secret "
            . "is never readable again, so the listing is all you have. **A pasted secret is final** -- to "
            . "replace one, delete it and create a new link. The form checks a `git_token` or `cloudflare_api_token` before saving it, with the "
            . "same calls the engine makes when it uses one: a git token against `verify_with.repo_url` "
            . "(required for the check), a Cloudflare token for its account and Tunnel access, plus the zone "
            . "of `verify_with.hostname` when given. A refused token is not stored and the link stays open. "
            . "`verification` on status then says whether it was checked.",
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['type'],
            properties: [
                new OA\Property(
                    property: 'type',
                    type: 'string',
                    description: 'The request field the reference will be passed in (e.g. `git_token`, `env_vars`). '
                        . 'Free-form snake_case -- the field the caller will send `vault:<id>` in. '
                        . 'The paste form shows help from `resources/vault/<type>.md` when that file exists, else the default help.',
                    example: 'git_token'
                ),
                new OA\Property(
                    property: 'scope',
                    type: 'string',
                    enum: ['project', 'global'],
                    description: "`project` (the default): usable by one project only -- the first one it is "
                        . "given to claims it, and any other project is refused. `global`: usable by any project "
                        . "that names it. Either way it is used only where a request passes its `vault:<id>`, "
                        . "and there may be any number of each type.",
                    default: 'project',
                ),
                new OA\Property(
                    property: 'expires_in',
                    type: 'integer',
                    minimum: 60,
                    description: 'Seconds until the secret expires. After that it is refused and deleted, with '
                        . 'no one having to remove it. Omitted, it never expires. A project that already used '
                        . 'the secret keeps the copy it stored.',
                    example: 86400,
                ),
                new OA\Property(
                    property: 'project',
                    type: 'string',
                    description: 'Only with `scope: project`: the project that owns the secret from the start, '
                        . 'so only that project can read it. The project need not exist yet. Omitted, the '
                        . 'first project that reads the secret owns it.',
                    example: 'shop',
                ),
                new OA\Property(
                    property: 'purpose',
                    type: 'string',
                    description: 'What this secret is for, in your words -- "deploy key for the shop repo", '
                        . '"Cloudflare token for the staging zone". Shown on the paste form, so the person '
                        . 'handing over a credential can see why, and returned by `list`. Several entries '
                        . 'share one `type`, and the secret can never be read back, so this is what tells '
                        . 'them apart later when deciding which to delete.',
                    maxLength: 255,
                    example: 'Deploy key for the shop repo',
                ),
                new OA\Property(
                    property: 'verify_with',
                    type: 'object',
                    description: 'What the pasted secret is checked against before it is saved. `git_token`: '
                        . '`repo_url`, the HTTPS repository the token must be able to read. '
                        . '`cloudflare_api_token`: `hostname` (optional), a domain whose zone the token must '
                        . 'see. A refused token is not stored and the form asks again; an unreachable host '
                        . 'saves it unchecked.',
                    properties: [
                        new OA\Property(property: 'repo_url', type: 'string', example: 'https://github.com/acme/shop'),
                        new OA\Property(property: 'hostname', type: 'string', example: 'shop.example.com'),
                    ],
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Vault entry created', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SecretVaultEntry'),
                ],
            )),
            new OA\Response(response: 422, description: 'Unknown type', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        // The type is the request field the ref will be passed in -- free
        // form, by design: any snake_case field an API call wants a vault
        // secret for is valid. Help for the paste form is found by
        // convention (resources/vault/<type>.md, else default.md), not by a
        // list here, so a type nobody planned for still works and still
        // explains itself.
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'scope' => ['nullable', 'string', 'in:' . implode(',', SecretVaultEntry::SCOPES)],
            'purpose' => ['nullable', 'string', 'max:255'],
            'verify_with' => ['nullable', 'array'],
            'project' => ['nullable', 'string'],
            'expires_in' => ['nullable', 'integer'],
        ]);

        // One place decides what minting means, because the console mints too
        // and the sealing rule must not differ between them.
        [$entry, $ref] = SecretMinter::mint(
            $validated['type'],
            $validated['scope'] ?? SecretVaultEntry::SCOPE_PROJECT,
            $validated['purpose'] ?? null,
            $validated['verify_with'] ?? null,
            $validated['project'] ?? null,
            isset($validated['expires_in']) ? (int) $validated['expires_in'] : null,
        );

        return new JsonResponse(['data' => [
            ...$this->format($entry),
            'url' => $this->formUrl($ref),
            'url_expires_in' => SecretVaultEntry::TTL_SECONDS,
        ]], 201);
    }

    #[OA\Get(
        path: '/vault/secrets',
        summary: 'List vault entries',
        description: 'Entries, newest first. **The secret is never included** and cannot be read back by any '
            . 'endpoint -- this is the inventory, not the values. Each row carries `ref` (`vault:<id>`, what '
            . 'a request passes and what status or delete take), `type`, `scope`, `project` (the project a '
            . '`project` entry belongs to, null until one claims it), `purpose`, `status` (pending, filled or '
            . 'abandoned) and the dates. `purpose` is what tells entries of the same type apart.',
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Only entries of this type.'),
            new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['project', 'global']), description: 'Only entries of this scope.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Vault entries', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SecretVaultEntry')),
                ],
            )),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'scope' => ['nullable', 'string', 'in:' . implode(',', SecretVaultEntry::SCOPES)],
        ]);

        $query = SecretVaultEntry::query()->orderByDesc('id')->limit(100);
        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (!empty($validated['scope'])) {
            $query->where('scope', $validated['scope']);
        }

        return new JsonResponse(['data' => $query->get()->map(fn (SecretVaultEntry $e) => $this->format($e))->all()]);
    }

    #[OA\Get(
        path: '/vault/secrets/{ref}',
        summary: 'Get one vault entry by its reference',
        description: 'The status of one entry. `ref` is its `vault:<id>`, or the bare id. The secret is never included.',
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        parameters: [
            new OA\Parameter(name: 'ref', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Vault entry', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SecretVaultEntry'),
                ],
            )),
            new OA\Response(response: 404, description: 'Unknown reference', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $ref): JsonResponse
    {
        $entry = $this->findByRef($ref);

        if ($entry === null) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        return new JsonResponse(['data' => $this->format($entry)]);
    }

    #[OA\Delete(
        path: '/vault/secrets/{ref}',
        summary: 'Delete a vault entry',
        description: 'Removes the entry and its secret. References to it stop resolving. This is also how a '
            . 'secret is *replaced*: a stored secret can never be overwritten, so delete it and create a new '
            . 'one. `ref` is its `vault:<id>`, or the bare id. A project that already used it keeps the '
            . 'copy it stored.',
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        parameters: [
            new OA\Parameter(name: 'ref', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Unknown reference', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $ref): JsonResponse
    {
        $entry = $this->findByRef($ref);

        if ($entry !== null) {
            $entry->delete();
        }

        return new JsonResponse(['data' => ['ref' => $ref, 'deleted' => true]]);
    }

    #[OA\Get(
        path: '/vault/config',
        summary: 'Which global secrets are stored',
        description: 'Lists the global entries that have a secret pasted, as `globals` (ref, type, purpose). '
            . 'Any project may use one by passing its `vault:<id>`; nothing uses one unasked.',
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        responses: [
            new OA\Response(response: 200, description: 'Vault configuration', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VaultConfig')],
            )),
        ],
    )]
    public function config(): JsonResponse
    {
        return new JsonResponse(['data' => $this->configPayload()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function configPayload(): array
    {
        return [
            'globals' => SecretVaultEntry::query()
                ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
                ->whereNotNull('filled_at')
                ->orderBy('type')
                ->orderBy('id')
                ->get()
                ->map(fn (SecretVaultEntry $e) => ['ref' => $e->reference(), 'type' => $e->type, 'purpose' => $e->purpose])
                ->all(),
        ];
    }

    /**
     * The form URL on the engine's own host: the base clients already use
     * (`config('app.url')`, which the certificate scripts keep in sync with
     * the served certificate) plus the path the web route serves. The engine
     * host, never a project domain -- this page belongs to the engine.
     */
    private function formUrl(string $ref): string
    {
        return rtrim((string) config('app.url'), '/') . '/vault/' . $ref;
    }

    /** `vault:<id>` or the bare id. */
    private function findByRef(string $ref): ?SecretVaultEntry
    {
        $id = SecretVaultEntry::idFromReference($ref);

        return $id === null ? null : SecretVaultEntry::query()->find($id);
    }

    /**
     * @return array<string, mixed> everything an agent may know; never the secret
     */
    private function format(SecretVaultEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ref' => $entry->reference(),
            'type' => $entry->type,
            'scope' => $entry->scope,
            // Who a `project` entry belongs to; null until the first project claims it.
            'project' => $entry->project,
            // Why it was asked for. The one field that tells two entries of
            // the same type apart, since the secret is never readable.
            'purpose' => $entry->purpose,
            'verify_with' => $entry->verify_with,
            'verification' => $entry->verification,
            'status' => $entry->status(),
            'used_count' => $entry->use_count,
            'last_used_at' => $entry->last_used_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            // Null: the secret never expires.
            'expires_at' => $entry->expires_at?->toIso8601String(),
            // When the paste form closes.
            'url_expires_at' => $entry->link_expires_at?->toIso8601String(),
        ];
    }
}