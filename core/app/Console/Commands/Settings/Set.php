<?php

namespace App\Console\Commands\Settings;

use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\NamingBaseSetting;
use App\Models\Setting;
use Illuminate\Console\Command;

class Set extends Command
{
    protected $signature = 'settings:set {name} {value}';

    protected $description = 'Set system setting';

    public function handle(): int
    {
        /** @var string */
        $name = $this->argument('name');
        /** @var string */
        $value = $this->argument('value');

        if (($message = self::rejection($name, $value)) !== null) {
            $this->error($message);

            return 1;
        }

        try {
            self::store($name, $value);
            $this->info("`{$name}` set to `{$value}`");
        } catch (\Exception $e) {
            $this->error('[ERROR] '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /** A base projects are named under re-renders every vhost when it changes. */
    private static function store(string $name, string $value): void
    {
        if (NamingBaseSetting::covers($name)) {
            NamingBaseSetting::save($name, $value);

            return;
        }

        Setting::set($name, $value);
    }

    private static function rejection(string $name, string $value): ?string
    {
        if ($name !== DomainPlan::SETTING_DEFAULT_TUNNEL) {
            return null;
        }

        return in_array($value, DomainPlan::TUNNELS, true)
            ? null
            : 'default_tunnel must be one of: '.implode(', ', DomainPlan::TUNNELS).'.';
    }
}
