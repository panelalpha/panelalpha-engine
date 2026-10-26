<?php

namespace App\Http\Resources;

use App\Models\SftpAccount;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SftpAccountResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $sftpAccount = $this->resource;
        assert($sftpAccount instanceof SftpAccount);

        return [
            'id' => $sftpAccount->id,
            'user_id' => $sftpAccount->user_id,
            'username' => $sftpAccount->username,
            'auth_method' => $sftpAccount->auth_method,
            'created_at' => $sftpAccount->created_at,
            'updated_at' => $sftpAccount->updated_at,
        ];
    }
}
