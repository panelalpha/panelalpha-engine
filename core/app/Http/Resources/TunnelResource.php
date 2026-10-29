<?php

namespace App\Http\Resources;

use App\Models\Tunnel;
use Illuminate\Http\Resources\Json\JsonResource;

class TunnelResource extends JsonResource
{
    public function toArray($request)
    {
        $tunnel = $this->resource;
        assert($tunnel instanceof Tunnel);

        $tunnel->loadMissing('domain');

        return [
            'id' => $tunnel->id,
            'hostname' => $tunnel->hostname,
            'provider' => $tunnel->provider,
            'purpose' => $tunnel->purpose(),
            'domain' => $tunnel->domain?->domain,
            'url' => 'https://'.$tunnel->hostname.'/',
            'valid_until' => $tunnel->expiresAt()?->toIso8601String(),
            'details' => $tunnel->getDetails(),
            'created_at' => $tunnel->created_at?->toIso8601String(),
            'updated_at' => $tunnel->updated_at?->toIso8601String(),
        ];
    }
}
