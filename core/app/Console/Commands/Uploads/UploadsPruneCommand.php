<?php

namespace App\Console\Commands\Uploads;

use App\Lib\Upload\Uploads;
use Illuminate\Console\Command;

class UploadsPruneCommand extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Delete archives uploaded for a create that expired unused';

    public function handle(Uploads $uploads): int
    {
        $removed = $uploads->prune();
        $this->line("Removed {$removed} expired upload(s).");

        return self::SUCCESS;
    }
}
