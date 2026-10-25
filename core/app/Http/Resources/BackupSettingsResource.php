<?php

namespace App\Http\Resources;

use App\Lib\Backup\ArchivePriority;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupSettingsResource extends JsonResource
{
    public function toArray($request)
    {
        $priority = $this->resource;
        assert($priority instanceof ArchivePriority);

        return $priority->toArray();
    }
}
