<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $user = $this->resource;
        assert(is_object($user) && get_class($user) === User::class);

        $user->loadMissing(['liveUser', 'stagingUser']);

        $resource = [
            'id' => $user->id,
            'username' => $user->username,
            'domain' => $user->domain,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'staging_of' => $user->relationLoaded('liveUser')
                ? ($user->liveUser?->username)
                : null,
            'staging' => $user->relationLoaded('stagingUser')
                ? ($user->stagingUser?->username)
                : null,
            'details' => $this->publicDetails($user),
            'config' => $user->getConfig(),
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if (!empty($request->get('with_domain_names'))) {
            $resource['domain_names'] = $user->listAllDomainNames();
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicDetails(User $user): array
    {
        $details = $user->getDetails();
        $passwordProtected = !empty($details['site_password_enabled'])
            && is_string($details['site_password_hash'] ?? null)
            && $details['site_password_hash'] !== '';

        unset(
            $details['git_token'],
            $details['env_vars'],
            $details['site_git'],
            $details['cloudflare_api_token'],
            $details['cloudflare_tunnel_token'],
            $details['site_password_hash'],
            $details['site_password_enabled'],
            $details['site_password_version'],
        );

        $details['password_protection'] = $passwordProtected;

        return $details;
    }
}
