<?php

namespace App\Lib\Overlay;

use App\Models\Domain;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Log;

/**
 * Sets and removes overlays, then re-renders the host proxy vhosts they reach.
 * Only nginx-proxy writes overlays into pages.
 */
class OverlayService
{
    public function __construct(
        private readonly OverlayStore $store = new OverlayStore,
        private readonly System $system = new System,
    ) {}

    public function setGlobal(Overlay $overlay): void
    {
        $this->store->setGlobal($overlay);
        $this->applyToAllProjects();
    }

    public function removeGlobal(): void
    {
        if ($this->store->global() === null) {
            return;
        }

        $this->store->forgetGlobal();
        $this->applyToAllProjects();
    }

    public function setProject(User $user, ?Overlay $overlay, bool $showGlobal): void
    {
        $this->store->setProject($user, $overlay, $showGlobal);
        $this->applyToProjects([$user]);
    }

    public function removeProject(User $user): void
    {
        $this->setProject($user, null, true);
    }

    /**
     * @return array{html: ?string, position: ?string, show_global: bool, global: ?array{html: string, position: string}, active: bool}
     */
    public function projectState(User $user): array
    {
        $own = $this->store->project($user);

        return [
            'html' => $own?->html,
            'position' => $own?->position->value,
            'show_global' => $this->store->showsGlobal($user),
            'global' => $this->store->global()?->toArray(),
            'active' => $this->store->effective($user) !== [],
        ];
    }

    public function global(): ?Overlay
    {
        return $this->store->global();
    }

    private function applyToAllProjects(): void
    {
        $this->applyToProjects(User::query()->whereHas('domains')->with('domains')->get()->all());
    }

    /**
     * @param  list<User>  $users
     */
    private function applyToProjects(array $users): void
    {
        $this->warnWhenNotEnforced();

        foreach ($users as $user) {
            $user->loadMissing('domains');
            foreach ($user->domains as $domain) {
                $this->rebuildHostVhost($domain);
            }
        }
        $this->system->webserver()->scheduleWebserverReloadInBackground();
    }

    private function rebuildHostVhost(Domain $domain): void
    {
        try {
            $this->system->webserver()->rebuildDomainConfig($domain);
        } catch (\Throwable $e) {
            Log::warning("Overlay: rebuilding the vhost of {$domain->domain} failed: ".$e->getMessage());
        }
    }

    private function warnWhenNotEnforced(): void
    {
        $webserver = $this->system->webserver()->getCurrentWebserver();
        if ($webserver !== 'nginx-proxy') {
            Log::warning("Overlay changed, but the webserver is '{$webserver}'; only nginx-proxy writes overlays into pages.");
        }
    }
}
