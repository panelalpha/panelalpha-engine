<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginSearchCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:search
                            {term : Search term}
                            {--fields= : Comma-separated fields to return}
                            {--per-page= : Results to return}';

    protected $description = 'Search wordpress.org for plugins via WP-CLI';

    protected function perform(WordPress $wordpress): mixed
    {
        $perPage = $this->optionalOption('per-page');

        return $wordpress->plugins()->search(
            (string) $this->argument('term'),
            $this->optionalOption('fields'),
            $perPage !== null ? (int) $perPage : null,
        );
    }
}
