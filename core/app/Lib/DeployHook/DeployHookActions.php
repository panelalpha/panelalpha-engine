<?php

namespace App\Lib\DeployHook;

use App\Lib\Git\GitActions;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use App\System\Project\Git as ProjectGit;

/**
 * Create, show, rotate and delete a checkout's Deploy Hook, shared by the
 * deploy-hook endpoints and `git:deploy-hook`. Each action takes its
 * endpoint's validated input; `path` selects the checkout as the git tools do.
 * A checkout without a hook is {@see DeployHookNotFound}.
 */
class DeployHookActions
{
    public function __construct(private readonly DeployHooks $hooks, private readonly GitActions $git)
    {
    }

    /**
     * The URL and, this once, the secret (`created` true). Asking again gives
     * the same URL and no secret, and never rotates it.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user, $params): array {
            $creation = $this->hooks->create($user, $git);

            $data = $this->describe($creation->hook, $this->hooks->warningFor($git)) + [
                'created' => $creation->created,
                'tls' => EngineTlsAdvisory::forProvider($params['provider'] ?? null),
            ];
            if ($creation->secret !== null) {
                // The only time the plaintext leaves the engine.
                $data['secret'] = $creation->secret;
            }

            return $data;
        });
    }

    /**
     * The hook's URL and when it was made, never the secret.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function show(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): array {
            $hook = $this->hooks->show($user, $git) ?? throw new DeployHookNotFound($git->pathKey());

            $data = $this->describe($hook, $this->hooks->warningFor($git)) + [
                'registered_url' => $hook->registered_url,
                'url_changed_since_registration' => $hook->addressChanged(),
                'tls' => EngineTlsAdvisory::forProvider(),
                'deliveries' => $this->deliveries($hook),
            ];

            return $data;
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function rotate(User $user, array $params): array
    {
        return $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): array {
            $rotation = $this->hooks->rotate($user, $git) ?? throw new DeployHookNotFound($git->pathKey());

            // As on create, the only time this secret leaves the engine.
            return $this->describe($rotation->hook, $this->hooks->warningFor($git)) + ['rotated' => true, 'secret' => $rotation->secret];
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function delete(User $user, array $params): void
    {
        $this->git->onCheckout($user, $params['path'] ?? null, function (ProjectGit $git) use ($user): void {
            if (!$this->hooks->delete($user, $git)) {
                throw new DeployHookNotFound($git->pathKey());
            }
        });
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
     * The hook's retained deliveries (HookDelivery::pruneOldest()'s two windows), newest
     * first, each with the outcome the request was answered with and the
     * result the queued work reached (or hasn't yet, or never will -- an
     * ignored or rejected delivery queued nothing).
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
