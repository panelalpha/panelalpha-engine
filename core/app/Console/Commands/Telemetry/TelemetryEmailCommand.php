<?php

namespace App\Console\Commands\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Models\Setting;
use Illuminate\Console\Command;

class TelemetryEmailCommand extends Command
{
    protected $signature = 'telemetry:email
                            {action : get|set}
                            {address? : Email address when action=set}';

    protected $description = 'Get or set the monitoring notification email and sync to monitoring';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        if ($action === 'get') {
            $email = NotificationPreferences::notifyEmail();
            $this->line($email ?? '(not set)');

            return self::SUCCESS;
        }

        if ($action !== 'set') {
            $this->error('Action must be get or set');

            return self::FAILURE;
        }

        $address = (string) ($this->argument('address') ?? '');
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('Valid email address required');

            return self::FAILURE;
        }

        Setting::set(NotificationPreferences::SETTING_NOTIFY_EMAIL, $address);
        $this->info("Notification email set to {$address}");

        $sync = NotificationPreferences::sync(force: true);
        $this->line($sync['ok'] ? $sync['message'] : '<error>'.$sync['message'].'</error>');

        return $sync['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
