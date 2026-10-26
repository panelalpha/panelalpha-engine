<?php

namespace App\Console\Commands\Settings;

use App\Models\Setting;
use Illuminate\Console\Command;

class Get extends Command
{
    protected $signature = 'settings:get {name}';

    protected $description = 'Get system setting';

    public function handle(): int
    {
        /** @var string */
        $name = $this->argument('name');

        try {
            $value = (string) Setting::get($name);
            $this->output->writeln($value);
        } catch (\Exception $e) {
            $this->error('[ERROR] '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
