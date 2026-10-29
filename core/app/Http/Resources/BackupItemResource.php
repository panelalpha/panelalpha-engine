<?php

namespace App\Http\Resources;

use App\Models\BackupItem;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupItemResource extends JsonResource
{
    public function toArray($request)
    {
        $item = $this->resource;
        assert($item instanceof BackupItem);

        return [
            'id' => $item->id,
            'remote_path' => $item->remote_path,
            'size_bytes' => $item->size_bytes,
            'details' => $item->details,
        ];
    }
}
