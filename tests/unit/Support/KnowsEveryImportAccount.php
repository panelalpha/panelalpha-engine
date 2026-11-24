<?php

namespace Tests\Support;

use App\Models\User;
use App\System\Services\Mysql\ImportAccount;

/** A Mysql double that knows an account for every database, named after it, so a copy's other steps can be tested. */
trait KnowsEveryImportAccount
{
    public function importAccount(User $owner, string $database): ImportAccount
    {
        return new ImportAccount($database);
    }
}
