<?php

namespace App\Console\Commands\System;

use App\Models\Setting;
use App\System;
use Illuminate\Console\Command;

class Lscache extends Command
{
    protected $signature = 'system:lscache {--enable} {--disable} {--rebuild-config}';

    protected $description = 'Enable or disable LiteSpeed cache.';

    public function handle(): int
    {
        $disabled = ! empty(Setting::get('disable-lscache'));

        $this->info('LS cache is '.($disabled ? 'disabled' : 'enabled'));

        $action = null;

        if ($this->options()['enable']) {
            $action = 'enable';
        }

        if ($this->options()['disable']) {
            if ($action) {
                $this->error('Too many arguments');

                return 1;
            }
            $action = 'disable';
        }

        if ($this->options()['rebuild-config']) {
            if ($action) {
                $this->error('Too many arguments');

                return 1;
            }
            $action = 'rebuild-config';
        }

        $system = new System;
        switch ($action) {
            case 'enable':
                Setting::set('disable-lscache', '0');
                $this->info('LS cache enabled.');
                $system->webserver()->toggleLscache();
                $this->info('LS cache config rebuilt.');
                break;
            case 'disable':
                Setting::set('disable-lscache', '1');
                $this->info('LS cache disabled.');
                $system->webserver()->toggleLscache();
                $this->info('LS cache config rebuilt.');
                break;
            case 'rebuild-config':
                $system->webserver()->toggleLscache();
                $this->info('LS cache config rebuilt.');
                break;
        }

        return 0;
    }
}
