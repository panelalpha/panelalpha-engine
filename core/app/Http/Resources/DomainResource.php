<?php

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $domain = $this->resource;
        assert($domain instanceof Domain);

        return [
            'id' => $domain->id,
            'user_id' => $domain->user_id,
            'domain' => $domain->domain,
            'type' => $domain->type,
            'details' => [
                'document_root' => $domain->getDocumentRoot(),
                'redirect_enabled' => $domain->redirectEnabled(),
                'redirect_url' => $domain->getRedirectUrl(),
                'force_https_redirect' => $domain->forceHttpsRedirectEnabled(),
                'aliases' => $domain->getAliases(),
            ],
            'created_at' => $domain->created_at,
            'updated_at' => $domain->updated_at,
        ];
    }
}
