<?php

namespace App\Lib\Overlay;

use App\Models\Setting;
use App\Models\User;

/**
 * Where overlays are kept: the global one in an Engine setting, a project's
 * own one and its choice to show the global one in the project's details.
 */
class OverlayStore
{
    public const string GLOBAL_SETTING = 'proxy_overlay';

    public const string DETAIL_OVERLAY = 'overlay';

    public const string DETAIL_SHOW_GLOBAL = 'overlay_show_global';

    public function global(): ?Overlay
    {
        $stored = Setting::get(self::GLOBAL_SETTING);
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        return Overlay::fromStored(json_decode($stored, true));
    }

    public function setGlobal(Overlay $overlay): void
    {
        Setting::set(self::GLOBAL_SETTING, json_encode($overlay->toArray(), JSON_THROW_ON_ERROR));
    }

    public function forgetGlobal(): void
    {
        Setting::forget(self::GLOBAL_SETTING);
    }

    public function project(User $user): ?Overlay
    {
        return Overlay::fromStored($user->getDetails()[self::DETAIL_OVERLAY] ?? null);
    }

    public function showsGlobal(User $user): bool
    {
        return ($user->getDetails()[self::DETAIL_SHOW_GLOBAL] ?? true) !== false;
    }

    public function setProject(User $user, ?Overlay $overlay, bool $showGlobal): void
    {
        $details = $user->getDetails();
        unset($details[self::DETAIL_OVERLAY], $details[self::DETAIL_SHOW_GLOBAL]);
        if ($overlay !== null) {
            $details[self::DETAIL_OVERLAY] = $overlay->toArray();
        }
        if (! $showGlobal) {
            $details[self::DETAIL_SHOW_GLOBAL] = false;
        }
        $user->details = $details;
        $user->save();
    }

    /**
     * What the proxy writes into this project's pages: the global overlay
     * first, unless the project hides it, then the project's own.
     *
     * @return list<Overlay>
     */
    public function effective(User $user): array
    {
        $global = $this->showsGlobal($user) ? $this->global() : null;

        return array_values(array_filter([$global, $this->project($user)]));
    }
}
