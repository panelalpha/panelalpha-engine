<?php

namespace App\Console\Commands\Wp;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** The project, and --path and --service to pick its install, for every WordPress command. */
trait SelectsWordPressInstall
{
    public function __construct()
    {
        parent::__construct();

        // Prepended, so the project stays the first argument ahead of the signature's own.
        $definition = $this->getDefinition();
        $definition->setArguments([
            new InputArgument('username', InputArgument::REQUIRED, 'Project username'),
            ...$definition->getArguments(),
        ]);
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'WordPress directory; omitted, the main domain document root, or the install found in the project containers');
        $this->addOption('service', null, InputOption::VALUE_REQUIRED, 'Compose service to run in, for WordPress in the project containers');
        $this->addOption('skip-plugins', null, InputOption::VALUE_NONE, 'Run without loading plugins and themes, so a broken one does not block the operation');
    }
}
