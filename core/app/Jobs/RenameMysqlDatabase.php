<?php

namespace App\Jobs;

use App\Exceptions\NotFoundException;
use App\Jobs\Concerns\AttachTask;
use App\Models\MysqlDatabase;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseRenamer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenameMysqlDatabase implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(
        public string $username,
        public string $from,
        public string $to,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $user = User::findByUsername($this->username)
                ?? throw new NotFoundException("Project '{$this->username}' not found.", User::class);
            $database = $user->mysqlDatabases()->getQuery()->where('database', $this->from)->first();
            if ($database === null) {
                throw new NotFoundException("MySQL database '{$this->from}' not found.", MysqlDatabase::class);
            }

            $mysql = (new System())->mysql();
            (new DatabaseRenamer($mysql, new DatabaseCopier($mysql)))->rename($user, $database, $this->to);
        });
    }
}
