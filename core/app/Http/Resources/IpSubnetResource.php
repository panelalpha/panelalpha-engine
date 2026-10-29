<?php

namespace App\Http\Resources;

use App\Models\IpSubnet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IpSubnetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subnet = $this->resource;
        assert($subnet instanceof IpSubnet);

        return [
            'id' => $subnet->id,
            'ip' => $subnet->ip,
            'mask' => $subnet->mask,
            'is_shared' => $subnet->is_shared,
            'created_at' => $subnet->created_at,
            'updated_at' => $subnet->updated_at,
            'assigned_ips' => new IpAssignedCollection($subnet->ipAssigned),
        ];
    }
}
