<?php

namespace App\Lib\Findings;

/** An exception that knows the finding it stands for, so inspect and a failed deploy name it the same. */
interface FindingException
{
    public function finding(): Finding;
}
