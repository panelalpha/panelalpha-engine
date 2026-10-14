<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class TaskSummaryCollection extends ResourceCollection
{
    public $collects = TaskSummaryResource::class;
}
