<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\HookProvider;

/**
 * One git provider's webhook API, which the engine writes to register, update and remove a Deploy Hook;
 * reading what a provider sends is {@see HookProvider}'s job.
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
     * The API addressing for a repository URL, or null when it cannot be read; any host is accepted, since a
     * client that named the provider may point it at a self-managed instance.
     */
    public function address(string $repoUrl): ?WebhookTarget;

    /** @return string the provider's own id for the hook it just created */
    public function create(WebhookTarget $target, string $token, string $url, string $secret): string;

    public function update(WebhookTarget $target, string $token, string $hookId, string $url, string $secret): void;

    public function delete(WebhookTarget $target, string $token, string $hookId): void;
}
