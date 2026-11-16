<?php

namespace App\Lib\DeployHook;

use App\Lib\DeployHook\Registration\BitbucketCloudRegistrar;
use App\Lib\DeployHook\Registration\CheckoutRemote;
use App\Lib\DeployHook\Registration\GithubRegistrar;
use App\Lib\DeployHook\Registration\GitlabRegistrar;
use App\Lib\DeployHook\Registration\WebhookRegistrar;
use App\Lib\DeployHook\Registration\WebhookTarget;
use App\Models\DeployHook;
use Illuminate\Support\Facades\Log;

/**
 * Registers a Deploy Hook as a webhook at its checkout's git provider, keeping the provider's id for rotation and deletion.
 * The provider is read from the URL's host, unless a client names one for a self-managed instance no host list would know.
 */
final class ProviderRegistrar
{
    /** @var list<WebhookRegistrar> */
    private array $registrars;

    /**
     * @param  list<WebhookRegistrar>|null  $registrars
     */
    public function __construct(?array $registrars = null)
    {
        $this->registrars = $registrars ?? [
            new GithubRegistrar,
            new GitlabRegistrar,
            new BitbucketCloudRegistrar,
        ];
    }

    /**
     * Create the provider webhook holding `$secret` at `$url`.
     *
     * @return ?ProviderRegistration null when no registrar serves the
     *                               repository, so the client is left to register the URL itself
     *
     * @throws ProviderRegistrationRefused
     */
    public function register(CheckoutRemote $remote, string $url, string $secret, ?string $provider = null): ?ProviderRegistration
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null) {
            return null;
        }
        if (! $remote->hasToken()) {
            throw ProviderRegistrationRefused::tokenMissing();
        }

        $hookId = $registrar->create($target, (string) $remote->token, $url, $secret);

        return new ProviderRegistration($registrar->name(), $hookId);
    }

    /**
     * Replace the URL and secret of a webhook registered earlier. False, the webhook left as it is, when the provider cannot
     * be addressed: the checkout was disconnected, or its remote moved.
     *
     * @throws ProviderRegistrationRefused
     */
    public function refresh(CheckoutRemote $remote, string $hookId, string $url, string $secret, ?string $provider): bool
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null || ! $remote->hasToken()) {
            return false;
        }
        $registrar->update($target, (string) $remote->token, $hookId, $url, $secret);

        return true;
    }

    /**
     * Remove a webhook registered earlier. False when the provider cannot be
     * addressed anymore, as in {@see refresh()}.
     *
     * @throws ProviderRegistrationRefused
     */
    public function forget(CheckoutRemote $remote, string $hookId, ?string $provider): bool
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null || ! $remote->hasToken()) {
            return false;
        }
        $registrar->delete($target, (string) $remote->token, $hookId);

        return true;
    }

    /**
     * {@see forget()} for a hook the engine is forgetting anyway: a provider that will not
     * answer must not keep alive a URL the engine has already stopped serving.
     */
    public function forgetQuietly(CheckoutRemote $remote, DeployHook $hook): void
    {
        try {
            $this->forget($remote, (string) $hook->provider_hook_id, $hook->provider);
        } catch (ProviderRegistrationRefused $e) {
            Log::warning('Could not delete the provider webhook of a forgotten deploy hook', [
                'deploy_hook_id' => $hook->id,
                'provider' => $hook->provider,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: ?WebhookRegistrar, 1: ?WebhookTarget}
     */
    private function target(CheckoutRemote $remote, ?string $provider): array
    {
        $registrar = $this->registrarForRemote($remote->repoUrl, $provider);

        return [$registrar, $registrar?->address($remote->repoUrl)];
    }

    /**
     * The registrar for a repository URL: by host when the URL names one, else
     * the provider the client named.
     */
    private function registrarForRemote(string $repoUrl, ?string $provider): ?WebhookRegistrar
    {
        $host = strtolower((string) (parse_url(trim($repoUrl), PHP_URL_HOST) ?: ''));
        foreach ($this->registrars as $registrar) {
            if ($host !== '' && in_array($host, $registrar->hosts(), true)) {
                return $registrar;
            }
        }
        if ($provider === null) {
            return null;
        }
        foreach ($this->registrars as $registrar) {
            if ($registrar->name() === $provider) {
                return $registrar;
            }
        }

        return null;
    }
}
