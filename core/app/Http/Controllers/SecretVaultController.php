<?php

namespace App\Http\Controllers;

use App\Lib\Vault\GlobalVault;
use App\Lib\Vault\RequestVault;
use App\Lib\Vault\SecretMinter;
use App\Models\SecretVaultEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * One-time browser handoff of a secret the API caller will not send.
 *
 * The flow: `create` mints an entry and returns a `vault:<ref>` plus the URL
 * of a form the *customer* opens in their browser and pastes the secret into.
 * The agent then passes `vault:<ref>` in place of the secret in any parameter
 * that accepts one (`git_token` on project_create / source_inspect,
 * `env_vars` values), and {@see \App\Lib\Vault\RequestVault} swaps it for the
 * plaintext at read time.
 *
 * The same raw ref appears in the URL and in the `vault:` value, so no lookup
 * can disagree with another; only its sha-256 is stored, so a database dump
 * contains no live form links. An entry is reusable until `expires_at` and
 * then gone -- reads are counted (`use_count`), not consumed.
 *
 * The secret itself is only ever stored encrypted and is never returned by
 * any of these endpoints; `show` reports whether one is present, which is
 * what an agent needs to know to wait for the paste.
 *
 * An entry minted with `scope: global` is the engine's own secret of that
 * type instead: one per type, no expiry, and every project created without a
 * secret of its own falls back to it ({@see \App\Lib\Vault\GlobalVault}), so
 * a Git or Cloudflare token is asked for once rather than at each project.
 * `config`/`updateConfig` below turn that sharing off for an engine that
 * wants the old per-project isolation.
 */
class SecretVaultController extends Controller
{
    #[OA\Post(
        path: '/vault/secrets',
        summary: 'Create a vault slot for a secret that will be pasted in a browser',
        description: "Mints a one-use-per-secret paste slot. Returns `ref` (`vault:<id>`, pass it where the "
            . "secret would go -- e.g. the `git_token` field of project_create or source_inspect) and `url` "
            . "(the form the customer opens and pastes the secret into). The entry is reusable until it "
            . "expires (`expires_in` seconds), then gone; a paste can be repeated while it lives. "
            . "**The secret never passes through the API caller** -- that is this mechanism's whole purpose, "
            . "for agents that must not relay a private-repository token through a conversation. "
            . "Pass `scope: global` instead to store it as the engine's own secret of that type, which does "
            . "not expire and which every project created without one of its own uses -- ask once, not at "
            . "every project. Give `purpose` so the entry can be recognised later: the secret is never "
            . "readable again, so the listing is all you have. **A pasted secret is final** -- it cannot be "
            . "overwritten from the form or by minting over it; to replace one, delete it and create a new "
            . "link. The form checks a `git_token` or `cloudflare_api_token` before saving it, with the "
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
                        . 'Free-form snake_case -- the field the caller will send `vault:<ref>` in. '
                        . 'The paste form shows help from `resources/vault/<type>.md` when that file exists, else the default help.',
                    example: 'git_token'
                ),
                new OA\Property(
                    property: 'scope',
                    type: 'string',
                    enum: ['request', 'global'],
                    description: "`request` (the default) is one secret for the calls you are about to make; it "
                        . "expires in an hour. `global` stores it as **the engine's** secret of that type: there is "
                        . "one per type, it does not expire, and every project created without a secret of its own "
                        . "uses it -- so ask the customer for a Git or Cloudflare token once rather than at each "
                        . "project. Minting `global` for a type that already has one re-opens the paste form so the "
                        . "secret can be replaced; the stored secret keeps working until it is. Pass `vault:global` "
                        . "in a field to use it explicitly.",
                    default: 'request',
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
        ]);

        // One place decides what minting means, because the console mints too
        // and the sealing rule must not differ between them.
        [$entry, $ref] = SecretMinter::mint(
            $validated['type'],
            $validated['scope'] ?? SecretVaultEntry::SCOPE_REQUEST,
            $validated['purpose'] ?? null,
            $validated['verify_with'] ?? null,
        );

        return new JsonResponse(['data' => [
            'id' => $entry->id,
            'ref' => $entry->isGlobal() ? RequestVault::PREFIX . RequestVault::GLOBAL_REF : RequestVault::PREFIX . $ref,
            'type' => $entry->type,
            'scope' => $entry->scope,
            'purpose' => $entry->purpose,
            'verify_with' => $entry->verify_with,
            'url' => $this->formUrl($ref),
            'status' => $entry->status(),
            // A global secret has no expiry; only the form does.
            'expires_in' => $entry->isGlobal() ? null : SecretVaultEntry::TTL_SECONDS,
            'url_expires_in' => SecretVaultEntry::TTL_SECONDS,
        ]], 201);
    }

    #[OA\Get(
        path: '/vault/secrets',
        summary: 'List vault entries',
        description: 'Every live or recently expired entry, newest first. **The secret is never included** '
            . 'and cannot be read back by any endpoint -- this is the inventory, not the values. Each row '
            . 'carries `id` (pass as `id:<n>` to status or delete), `type`, `purpose`, `scope`, `status` '
            . '(pending, filled or expired) and the dates. `purpose` is what tells two entries of the same '
            . 'type apart when deciding which to delete.',
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Only entries of this type.'),
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
        ]);

        $query = SecretVaultEntry::query()->orderByDesc('created_at')->limit(100);
        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        return new JsonResponse(['data' => $query->get()->map(fn (SecretVaultEntry $e) => $this->format($e))->all()]);
    }

    #[OA\Get(
        path: '/vault/secrets/{ref}',
        summary: 'Get one vault entry by its reference',
        description: 'The status of one entry. `ref` is the full `vault:<id>` value `create` returned, '
            . '`global:<type>` for an engine-wide secret, or `id:<n>` from `list`. The secret is never included.',
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
            . 'one. `ref` accepts the `vault:<id>` create returned, `global:<type>` for an engine-wide '
            . 'secret, or `id:<n>` from `list`.',
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
        summary: 'Whether projects share the engine-wide secrets',
        description: 'Reports `project_scoped_tokens`. False (the default) means a project created without a '
            . 'Git or Cloudflare token uses the engine-wide one, if a `global` vault entry holds it.',
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

    #[OA\Put(
        path: '/vault/config',
        summary: 'Turn engine-wide secret sharing on or off',
        description: "Set `project_scoped_tokens` true to keep every project on the credentials it was given: "
            . "global entries stay stored but nothing reaches for them on a project's behalf, which is what a "
            . "multi-customer engine wants. False (the default) shares them. Existing projects are not "
            . "rewritten either way -- this only decides what a project *without* its own credential falls back "
            . "to, so the switch can be flipped back.",
        security: [['bearerAuth' => []]],
        tags: ['Secret Vault'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['project_scoped_tokens'],
            properties: [new OA\Property(property: 'project_scoped_tokens', type: 'boolean')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Vault configuration', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VaultConfig')],
            )),
        ],
    )]
    public function updateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_scoped_tokens' => ['required', 'boolean'],
        ]);

        GlobalVault::setProjectScoped((bool) $validated['project_scoped_tokens']);

        return new JsonResponse(['data' => $this->configPayload()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function configPayload(): array
    {
        $scoped = GlobalVault::projectScoped();

        return [
            'project_scoped_tokens' => $scoped,
            'global_secrets_shared' => !$scoped,
            // Which engine-wide secrets actually exist, so a caller can tell
            // "sharing is on" from "sharing is on and there is something to
            // share" without listing entries and filtering.
            'global_types' => SecretVaultEntry::query()
                ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
                ->whereNotNull('filled_at')
                ->orderBy('type')
                ->pluck('type')
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

    /**
     * Path and resolver share one strip: `vault:<id>` from the API, bare `<id>`
     * from the URL -- both accepted, one spelling of the truth.
     */
    private function findByRef(string $ref): ?SecretVaultEntry
    {
        if (str_starts_with($ref, RequestVault::PREFIX)) {
            $ref = substr($ref, strlen(RequestVault::PREFIX));
        }

        // A global entry outlives the link it was pasted through, and
        // re-minting replaces that link, so a caller holding last week's ref
        // has no way to name it. `global:<type>` is the name that keeps
        // working -- it is what the entry *is*, not how it was filled. The
        // colon cannot occur in a minted ref (Str::random is alphanumeric),
        // so the two spellings can never collide.
        if (str_starts_with($ref, RequestVault::GLOBAL_REF . ':')) {
            return SecretVaultEntry::globalFor(substr($ref, strlen(RequestVault::GLOBAL_REF) + 1));
        }

        // `id:<n>`, the handle a listing hands out. Prefixed rather than bare
        // digits so it can never be mistaken for a minted ref, and the only
        // way to address a request entry after the one time its ref was shown.
        if (str_starts_with($ref, 'id:')) {
            $id = substr($ref, 3);

            return ctype_digit($id) ? SecretVaultEntry::query()->find((int) $id) : null;
        }

        return SecretVaultEntry::query()
            ->where('ref_hash', SecretVaultEntry::hashRef($ref))
            ->first();
    }

    /**
     * @return array<string, mixed> everything an agent may know; never the secret
     */
    private function format(SecretVaultEntry $entry): array
    {
        return [
            // The handle a listing can act on. A request entry's ref is
            // unknowable (only its hash is stored) and a re-mint rotates a
            // global's, so without this a listed entry could be read about
            // and never deleted.
            'id' => $entry->id,
            // A global's ref is not a secret at all: it is the type.
            'ref' => $entry->isGlobal() ? RequestVault::GLOBAL_REF . ':' . $entry->type : null,
            'type' => $entry->type,
            'scope' => $entry->scope,
            // Why it was asked for. The one field that tells two entries of
            // the same type apart, since the secret is never readable.
            'purpose' => $entry->purpose,
            'verify_with' => $entry->verify_with,
            'verification' => $entry->verification,
            'status' => $entry->status(),
            'used_count' => $entry->use_count,
            'last_used_at' => $entry->last_used_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'expires_at' => $entry->expires_at?->toIso8601String(),
            // When the paste form closes, which for a global is the only
            // clock there is.
            'url_expires_at' => $entry->link_expires_at?->toIso8601String(),
        ];
    }
}