<?php

namespace App\Lib\DeployHook;

use App\Lib\DeployHook\Registration\CheckoutRemote;
use App\Lib\Git\GitActions;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use App\System\Project\Git as ProjectGit;

/**
 * Create, show, rotate and delete a checkout's Deploy Hook for the endpoints and `git:deploy-hook` ({@see DeployHookNotFound}
 * when there is none); with `register`, the provider webhook follows ({@see ProviderRegistrar}), so a client needs no provider API.
 */
class DeployHookActions
{
    public const NO_REGISTRAR_WARNING = 'The engine has no provider API for this checkout\'s git host, so no webhook was registered: register the hook URL in the git host yourself.';

    public const ROTATE_TO_REGISTER_WARNING = 'The hook\'s secret cannot be read back, so it was not registered at the provider: rotate the hook to register a new secret there.';

    public const PROVIDER_NOT_UPDATED_WARNING = 'The provider webhook could not be updated with the new URL and secret: update or re-register it in the git host.';

    public const ROTATE_TO_SIGN_WARNING = 'The hook\'s secret predates signed calls, so GitLab was given it as a secret token only: rotate the hook to have GitLab sign every call.';

    public function __construct(
        private readonly DeployHooks $hooks,
        private readonly GitActions $git,
        private readonly ProviderRegistrar $registrar,
    ) {}

    /**
     * The URL and, this once, the secret (`created` true); asking again gives the same URL, no secret, and never rotates
     * it. With `register` the webhook is made at the provider too, and `registration` says so.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function create(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user, $params): array {
            $creation = $this->hooks->create($user, $git);
            $warning = $this->registerKeepingTheSecret($user, $git, $creation, $params);

            return $this->created($git, $creation, $params, $warning);
        });
    }

    /**
     * A refused registration keeps the hook this call made, so the refusal carries
     * that hook's answer: its secret cannot be shown by any later call.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ProviderRegistrationRefused
     */
    private function registerKeepingTheSecret(User $user, ProjectGit $git, HookCreation $creation, array $params): ?string
    {
        try {
            return $this->register($user, $git, $creation, $params);
        } catch (ProviderRegistrationRefused $e) {
            throw $creation->secret === null ? $e : $e->withCreatedHook($this->created($git, $creation, $params, null));
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function created(ProjectGit $git, HookCreation $creation, array $params, ?string $warning): array
    {
        $data = $this->describe($creation->hook, $this->hooks->warningFor($git)) + [
            'created' => $creation->created,
            'tls' => EngineTlsAdvisory::forProvider($params['provider'] ?? null),
            'registration' => $this->registration($creation->hook),
        ];
        if ($creation->secret !== null) {
            $data['secret'] = $creation->secret;
        }
        if ($warning !== null) {
            $data['registration_warning'] = $warning;
        }

        return $data;
    }

    /**
     * The hook's URL and when it was made, never the secret.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function show(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): array {
            $hook = $this->hooks->show($user, $git) ?? throw new DeployHookNotFound($git->pathKey());

            return $this->describe($hook, $this->hooks->warningFor($git)) + [
                'registered_url' => $hook->registered_url,
                'url_changed_since_registration' => $hook->addressChanged(),
                'registration' => $this->registration($hook),
                'tls' => EngineTlsAdvisory::forProvider(),
                'deliveries' => $this->deliveries($hook),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function rotate(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): array {
            $rotation = $this->hooks->rotate($user, $git) ?? throw new DeployHookNotFound($git->pathKey());
            $warning = $this->refresh($user, $git, $rotation);

            $data = $this->describe($rotation->hook, $this->hooks->warningFor($git)) + [
                'rotated' => true,
                'secret' => $rotation->secret,
                'registration' => $this->registration($rotation->hook),
            ];
            if ($warning !== null) {
                $data['registration_warning'] = $warning;
            }

            return $data;
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function delete(User $user, array $params): void
    {
        $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): void {
            $hook = $this->hooks->show($user, $git) ?? throw new DeployHookNotFound($git->pathKey());
            if ($hook->isRegistered()) {
                $this->registrar->forgetQuietly(CheckoutRemote::of($user, $git->pathKey()), $hook);
            }
            if (! $this->hooks->delete($user, $git)) {
                throw new DeployHookNotFound($git->pathKey());
            }
        });
    }

    /**
     * Make the provider webhook when `register` was asked for, with the secret an existing hook holds; one whose ciphertext
     * fails to decrypt is a returned warning rather than a rotation the caller did not ask for.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ProviderRegistrationRefused
     */
    private function register(User $user, ProjectGit $git, HookCreation $creation, array $params): ?string
    {
        if (($params['register'] ?? false) !== true || $creation->hook->isRegistered()) {
            return null;
        }

        $secret = $creation->secret ?? $creation->hook->secret();
        if ($secret === null) {
            return self::ROTATE_TO_REGISTER_WARNING;
        }

        $registration = $this->registrar->register(
            CheckoutRemote::of($user, $git->pathKey()),
            $creation->hook->url(),
            $secret,
            $this->named($params),
        );
        if ($registration === null) {
            return self::NO_REGISTRAR_WARNING;
        }
        $creation->hook->rememberRegistration($registration->provider, $registration->hookId);

        return $this->unsignedWarning($registration, $secret);
    }

    /** GitLab signs only with a `whsec_` secret, which a hook made before 2.2 does not hold. */
    private function unsignedWarning(ProviderRegistration $registration, string $secret): ?string
    {
        return $registration->provider === TlsInstructions::GITLAB && ! GitlabProvider::canSignWith($secret)
            ? self::ROTATE_TO_SIGN_WARNING
            : null;
    }

    /**
     * A rotation changes the hook's URL and secret, so the provider webhook has
     * to follow both. Null when there was nothing to update.
     */
    private function refresh(User $user, ProjectGit $git, HookRotation $rotation): ?string
    {
        $hook = $rotation->hook;
        if (! $hook->isRegistered()) {
            return null;
        }
        try {
            $done = $this->registrar->refresh(
                CheckoutRemote::of($user, $git->pathKey()),
                (string) $hook->provider_hook_id,
                $hook->url(),
                $rotation->secret,
                $hook->provider,
            );
        } catch (ProviderRegistrationRefused $e) {
            return $this->notRefreshed($hook, $e);
        }

        return $done ? null : self::PROVIDER_NOT_UPDATED_WARNING;
    }

    /**
     * The rotation stands whatever the provider answered: it is what retires a leaked URL and secret. A webhook the
     * provider answers 404 for is forgotten, so a create with `register` can make a new one.
     */
    private function notRefreshed(DeployHook $hook, ProviderRegistrationRefused $refusal): string
    {
        if ($refusal->providerStatus === 404) {
            $hook->forgetRegistration();
        }

        return self::PROVIDER_NOT_UPDATED_WARNING.' '.$refusal->getMessage();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function named(array $params): ?string
    {
        $provider = $params['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * What every response says about a hook: where it is, never its secret,
     * and the warning about what a push does to the checkout when there is one.
     *
     * @return array<string, mixed>
     */
    private function describe(DeployHook $hook, ?string $warning): array
    {
        $data = [
            'url' => $hook->url(),
            'path' => $hook->path_key,
            'created_at' => $hook->created_at?->toIso8601String(),
            'updated_at' => $hook->updated_at?->toIso8601String(),
        ];
        if ($warning !== null) {
            $data['warning'] = $warning;
        }

        return $data;
    }

    /**
     * The provider webhook this hook is registered as, or null. The provider's
     * own id, never the secret the hook was registered with.
     *
     * @return ?array{provider_hook_id: string, registered_at: ?string}
     */
    private function registration(DeployHook $hook): ?array
    {
        if (! $hook->isRegistered()) {
            return null;
        }

        return [
            'provider_hook_id' => (string) $hook->provider_hook_id,
            'registered_at' => $hook->registered_at?->toIso8601String(),
        ];
    }

    /**
     * The hook's retained deliveries (HookDelivery::pruneOldest()'s two windows), newest first, each with the outcome
     * it was answered with and the result its queued work reached, if any: an ignored or rejected delivery queued nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function deliveries(DeployHook $hook): array
    {
        return $hook->deliveries()
            ->orderByDesc('id')
            ->limit(HookDelivery::KEEP_HISTORY + HookDelivery::KEEP_REJECTED)
            ->get()
            ->map(static fn (HookDelivery $delivery): array => [
                'provider' => $delivery->provider,
                'event' => $delivery->event,
                'branch' => $delivery->branch,
                'commit' => $delivery->commit,
                'outcome' => $delivery->outcome,
                'reason' => $delivery->reason,
                'result' => $delivery->result,
                'detail' => $delivery->detail,
                'deploy_id' => $delivery->deploy_id,
                'created_at' => $delivery->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
