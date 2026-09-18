<?php

namespace App\Console\Commands\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Models\Setting;
use Illuminate\Console\Command;

class TelemetryDisableCommand extends Command
{
    protected $signature = 'telemetry:disable';

    protected $description = 'Disable telemetry sending and tell monitoring to stop email probes';

    public function handle(): int
    {
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '0');
        $this->info('Telemetry sending disabled.');

        $sync = NotificationPreferences::sync(false, force: true);
        $this->line($sync['ok'] ? $sync['message'] : '<comment>'.$sync['message'].' (local disable still applied)</comment>');

        return self::SUCCESS;
    }
}
