<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpAutoUpdateSetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:auto-update:set
                            {core : Core auto-update (off|on|minor)}
                            {plugins : Plugins auto-update (off|on)}
                            {themes : Themes auto-update (off|on)}';

    protected $description = 'Set WordPress auto-update settings for core, plugins, and themes';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->autoUpdate()->set([
            'core' => (string) $this->argument('core'),
            'plugins' => (string) $this->argument('plugins'),
            'themes' => (string) $this->argument('themes'),
        ]);
    }
}
