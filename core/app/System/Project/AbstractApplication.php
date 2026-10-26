<?php

namespace App\System\Project;

use App\Models\Domain;

abstract class AbstractApplication
{
    abstract public function id(): string;

    abstract public function rootPath(): string;

    /**
     * @return list<Domain>
     */
    abstract public function domains(): array;
}
