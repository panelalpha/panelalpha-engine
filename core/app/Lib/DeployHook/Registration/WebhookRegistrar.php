<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\HookProvider;

/**
 * One git provider's webhook API, as the engine uses it to register, update and
 * remove a Deploy Hook. Separate from {@see HookProvider},
 * which reads what a provider sends: this one writes, and only three providers
 * have an API the engine can write to.
 */
interface WebhookRegistrar
{
    /** The name stored with a registration (`github`). */
    public function name(): string;

    /**
     * The hosts this provider is served from when the checkout does not name
     * it, so a repository URL alone says which registrar to use.
     *
     * @return list<string>
     */
    public function hosts(): array;

    /**
     * The API addressing for a repository URL, or null when the URL cannot be
     * read. Deliberately does not require a canonical host: a client that named
     * the provider may point this at a self-managed instance, which may be asked
     * where its API is served.
     */
    public function address(string $repoUrl): ?WebhookTarget;

    /** @return string the provider's own id for the hook it just created */
    public function create(WebhookTarget $target, string $token, string $url, string $secret): string;

    public function update(WebhookTarget $target, string $token, string $hookId, string $url, string $secret): void;

    public function delete(WebhookTarget $target, string $token, string $hookId): void;
}
