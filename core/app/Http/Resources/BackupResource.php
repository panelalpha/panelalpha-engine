<?php

namespace App\Http\Resources;

use App\Models\Backup as BackupRecord;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupResource extends JsonResource
{
    public function toArray($request)
    {
        $backup = $this->resource;
        assert($backup instanceof BackupRecord);

        $data = [
            'id' => $backup->id,
            'username' => $backup->username,
            'container_id' => $backup->container_id,
            'kind' => $backup->kind,
            'note' => $backup->note,
            'contents' => $backup->contents,
            'async_status' => $backup->async_status,
            'error' => $backup->error,
            'size_bytes' => $this->sizeBytes($backup),
            'created_at' => $backup->created_at->toIso8601String(),
            'updated_at' => $backup->updated_at->toIso8601String(),
        ];

        if ($backup->relationLoaded('container') && $backup->container !== null) {
            $data['container'] = (new BackupContainerResource($backup->container))->toArray($request);
        }

        if ($backup->relationLoaded('items')) {
            $data['items'] = BackupItemResource::collection($backup->items)->toArray($request);
        }

        return $data;
    }

    private function sizeBytes(BackupRecord $backup): int
    {
        if (! $backup->relationLoaded('items')) {
            return 0;
        }

        return (int) $backup->items->sum('size_bytes');
    }
}
