<?php

namespace App\Lib\DeployHook;

use App\Lib\Net\EgressRefusedException;

/**
 * A webhook registration the git provider did not accept, or could not be
 * asked for. Carried out of the library rather than rendered there, because
 * only the HTTP boundary knows a refusal is a 422 body and an unreachable
 * provider a 502.
 */
final class ProviderRegistrationRefused extends \RuntimeException
{
    /**
     * @param  ?int  $providerStatus  what the provider answered when it refused, null when it was not asked or not reached
     * @param  ?array<string, mixed>  $createdHook  the hook the refused call created, as its answer would have shown it
     */
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $problemCode,
        public readonly ?int $providerStatus = null,
        public readonly ?array $createdHook = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The same refusal, carrying the hook the call created before it was refused:
     * that answer is the only one that ever shows the hook's secret.
     *
     * @param  array<string, mixed>  $hook
     */
    public function withCreatedHook(array $hook): self
    {
        return new self($this->getMessage(), $this->httpStatus, $this->problemCode, $this->providerStatus, $hook, $this);
    }

    public static function tokenMissing(): self
    {
        return new self(
            'The checkout has no git token, so the engine cannot register a webhook at the provider. '
                .'Connect the checkout with a token, or register the URL in the git host yourself.',
            422,
            'provider_token_missing',
        );
    }

    public static function permissionDenied(string $provider, int $status): self
    {
        return new self(
            "The git provider refused the webhook registration: {$provider} answered {$status}. "
                .'The token needs permission to manage repository webhooks '
                .'(GitHub admin:repo_hook on the repository, GitLab maintainer or owner, Bitbucket admin).',
            422,
            'provider_permission_denied',
            $status,
        );
    }

    public static function hostRefused(string $provider): self
    {
        return new self(
            "The engine did not call {$provider}: it calls a provider API only at a public address of the "
                .'repository\'s own host, and never follows a redirect to another host. '
                .'Register the hook URL in the git host yourself.',
            422,
            EgressRefusedException::CODE,
        );
    }

    public static function unreachable(string $provider, string $reason): self
    {
        return new self("The webhook registration at {$provider} could not be completed: {$reason}.", 502, null);
    }
}
