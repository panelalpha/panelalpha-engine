<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpLanguageSetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:language:set
                            {language : Locale code (e.g. pl_PL)}';

    protected $description = 'Set the WordPress site language';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->language()->set((string) $this->argument('language'));
    }
}
