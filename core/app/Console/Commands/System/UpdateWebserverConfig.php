<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

class UpdateWebserverConfig extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:update-webserver-config'];

    protected $signature = 'system:webserver:update {name} {value}';

    protected $description = 'Update webserver config entry.';

    public function handle(): int
    {
        /** @var string */
        $name = $this->argument('name');
        /** @var string */
        $value = $this->argument('value');

        try {
            $system = new System;
            $webserver = $system->webserver();
            $webserver->updateConfig($name, $value);
            $this->info("`{$name}` set to `{$value}`");
        } catch (\Exception $e) {
            $this->error('[ERROR] '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
