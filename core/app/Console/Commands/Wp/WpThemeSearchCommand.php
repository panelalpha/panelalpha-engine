<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeSearchCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:search
                            {term : Search term}
                            {--fields= : Comma-separated fields to return}
                            {--per-page= : Results to return}';

    protected $description = 'Search wordpress.org for themes via WP-CLI';

    protected function perform(WordPress $wordpress): mixed
    {
        $perPage = $this->optionalOption('per-page');

        return $wordpress->themes()->search(
            (string) $this->argument('term'),
            $this->optionalOption('fields'),
            $perPage !== null ? (int) $perPage : null,
        );
    }
}
