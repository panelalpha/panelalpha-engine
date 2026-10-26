<?php

namespace App\Http\Resources;

use App\Models\FtpAccount;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FtpAccountResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        /** @var FtpAccount $this */
        $diskUsage = $this->getDiskUsage();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->user,
            'directory' => $this->directory,
            'details' => $this->details,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'disk_usage_mb' => $diskUsage,
        ];
    }
}
