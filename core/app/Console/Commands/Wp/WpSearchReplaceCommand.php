<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpSearchReplaceCommand extends WordPressCommand
{
    protected $signature = 'wordpress:search-replace
                            {search : Search string}
                            {replacement : Replacement string}
                            {extra?* : Table names and --dry-run, --precise, --all-tables, --all-tables-with-prefix, --regex, --skip-columns=, --regex-delimiter=}';

    protected $description = 'Search-replace a string across the WordPress install';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->searchReplace()->run((string) $this->argument('search'), (string) $this->argument('replacement'), $this->listArgument('extra'));
    }
}
