<?php

namespace App\Http\Controllers\Web;

use App\Lib\Vault\PasteCheck;
use App\Models\SecretVaultEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The browser side of the vault: the form the customer pastes a secret into.
 *
 * Unauthenticated by design -- the 48-character ref in the URL *is* the
 * capability, single-entry and short-lived, the same trust model as
 * `GET /projects/{username}/app/sso-token`. The routes throttle both verbs,
 * the token alone decides which entry is addressed, and the POST never sends
 * the secret back to the page: a saved confirmation, never a rendered value.
 *
 * Lives on the engine's own host under `routes/web.php` (no /api prefix, no
 * bearer auth), so an MCP agent can hand the URL to a customer as-is.
 *
 * A type with no screen of its own falls back to the generic paste page: the
 * caller names the type, so an unplanned one must still work.
 */
class VaultFormController
{
    private const SCREENS = [
        SecretVaultEntry::TYPE_GIT_TOKEN => 'vault.screens.git-token',
        SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN => 'vault.screens.cloudflare',
    ];

    private const DEFAULT_SCREEN = 'vault.screens.generic';

    public function show(string $token): View
    {
        $entry = $this->find($token);

        if ($entry === null) {
            return $this->unavailable(
                'This link is not usable',
                'It does not lead to a secret any more. Ask the assistant that gave it to you for a new one.'
            );
        }

        if ($entry->isSealed()) {
            return $this->sealed();
        }

        if ($this->closed($entry)) {
            return $this->expired($entry);
        }

        return $this->screen($token, $entry);
    }

    public function store(Request $request, string $token): Response|View
    {
        $validated = $request->validate([
            'secret' => ['required', 'string', 'max:8192', 'not_in:'],
        ]);

        $entry = $this->find($token);

        if ($entry === null) {
            return $this->unavailable(
                'This link is not usable',
                'It does not lead to a secret any more. Ask the assistant that gave it to you for a new one.'
            );
        }

        // Checked again here, not just in show(): the form is a POST, and
        // anyone holding the link can repeat it without ever loading the page.
        if ($entry->isSealed()) {
            return $this->sealed();
        }

        if ($this->closed($entry)) {
            return $this->expired($entry);
        }

        // Before the save, because a stored secret is final: a token that
        // does not work must leave the link open for another try.
        $check = app(PasteCheck::class)->run($entry, $validated['secret']);
        if ($check['rejected'] !== null) {
            return response($this->screen($token, $entry, $check['rejected']), 422);
        }

        $entry->setSecret($validated['secret']);
        $entry->verification = $check['verification'];
        $entry->save();

        return view('vault.saved', ['verification' => $entry->verification]);
    }

    private function screen(string $token, SecretVaultEntry $entry, ?string $error = null): View
    {
        return view(self::SCREENS[$entry->type] ?? self::DEFAULT_SCREEN, [
            'token' => $token,
            'entry' => $entry,
            'helpHtml' => SecretVaultEntry::help($entry->type),
            'note' => $this->note($entry),
            'error' => $error,
            'checkTarget' => PasteCheck::describe($entry),
        ]);
    }

    /**
     * Whether this page will still take a paste.
     *
     * Two clocks, because a global entry's secret has no expiry while the
     * link that fills it does: the form closes on whichever ran out. For a
     * request entry they are the same moment and this reads as it always did.
     */
    private function closed(SecretVaultEntry $entry): bool
    {
        return $entry->expired() || $entry->linkExpired();
    }

    /**
     * The secret is already set, and this link cannot change it.
     *
     * Said plainly rather than hidden behind "this link is not usable": the
     * person is holding a live link to a real entry, and the useful thing to
     * tell them is that the value is in place and what it takes to replace
     * it.
     */
    private function sealed(): View
    {
        return $this->unavailable(
            'This secret is already set',
            'It cannot be changed from this page. To replace it, ask for the old one to be deleted '
            . 'and a new link created.'
        );
    }

    /** Unknown and expired both land here, and neither says which. */
    private function unavailable(string $heading, string $message): View
    {
        return view('vault.screens.unavailable', [
            'heading' => $heading,
            'message' => $message,
        ]);
    }

    private function expired(SecretVaultEntry $entry): View
    {
        $at = $entry->link_expires_at ?? $entry->expires_at;

        return $this->unavailable(
            'This link expired',
            'It expired at ' . ($at?->format('Y-m-d H:i T') ?? 'an earlier time') . '. Ask the assistant that gave it to you for a new one.'
        );
    }

    /**
     * What the save means. A sealed entry never reaches here, so there is no
     * "this replaces what is there" case left to describe -- only how far
     * the save reaches, which a global entry has to say: it is not for one
     * project, it is what every project without its own will use.
     */
    private function note(SecretVaultEntry $entry): string
    {
        $stored = 'Saved once and for all: stored encrypted on your server, never shown to anyone again '
            . '(including your AI agent), and it cannot be changed from this page afterwards.';

        if ($entry->isGlobal()) {
            $stored = 'Saved for this whole server: every project that has none of its own will use it. ' . $stored;
        }

        return $stored;
    }

    private function find(string $token): ?SecretVaultEntry
    {
        return SecretVaultEntry::query()
            ->where('ref_hash', SecretVaultEntry::hashRef($token))
            ->first();
    }
}
