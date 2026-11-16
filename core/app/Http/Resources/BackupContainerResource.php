<?php

namespace App\Http\Resources;

use App\Models\BackupContainer;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupContainerResource extends JsonResource
{
    public function toArray($request)
    {
        $container = $this->resource;
        assert($container instanceof BackupContainer);

        return [
            'id' => $container->id,
            'name' => $container->name,
            'driver' => $container->driver,
            'location' => $container->location,
            'has_credentials' => $container->credentials !== null,
            'default' => (bool) $container->is_default,
            'created_at' => $container->created_at->toIso8601String(),
            'updated_at' => $container->updated_at->toIso8601String(),
        ];
    }
}
