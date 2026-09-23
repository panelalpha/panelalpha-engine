<?php

namespace App\Console\Commands\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Console\Command;

class TelemetryEnableCommand extends Command
{
    protected $signature = 'telemetry:enable';

    protected $description = 'Enable telemetry sending and sync notification preferences to monitoring';

    public function handle(): int
    {
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '1');
        $this->info('Telemetry sending enabled.');

        $sync = NotificationPreferences::sync(true, force: true);
        $this->line($sync['ok'] ? $sync['message'] : '<error>'.$sync['message'].'</error>');

        return $sync['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
