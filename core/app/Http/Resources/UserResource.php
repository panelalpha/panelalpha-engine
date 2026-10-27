<?php

namespace App\Http\Resources;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Host\ProjectDisk;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\ProjectListFacts;
use App\Lib\Project\ProjectOverview;
use App\Lib\Usage\ProjectBandwidth;
use App\Models\User;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\Networking;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array. `unreadable_secrets` names the
     * secrets stored for this project that cannot be decoded ([] when all
     * decode); `app` is the same facts normalized: what it is, whether it is
     * up, where it answers; `usage` is the CPU and memory of the project's DinD
     * container, sampled every minute.
     *
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $user = $this->resource;
        assert(is_object($user) && get_class($user) === User::class);

        $user->loadMissing(['liveUser', 'stagingUser']);
        $facts = $this->listFacts($request, $user);

        $resource = [
            'id' => $user->id,
            'username' => $user->username,
            'domain' => $user->domain,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'protected' => $user->isProtected(),
            'staging_of' => $user->relationLoaded('liveUser')
                ? ($user->liveUser?->username)
                : null,
            'staging' => $user->relationLoaded('stagingUser')
                ? ($user->stagingUser?->username)
                : null,
            'details' => $this->publicDetails($user),
            'app_credentials' => $this->appCredentials($user),
            'disk' => $this->disk($user),
            'bandwidth' => $this->bandwidth($user, $request),
            'unreadable_secrets' => $user->unreadableSecrets(),
            'warning' => $user->unreadableSecretsWarning(),
            'app' => ProjectOverview::for($user, $facts),
            'usage' => $facts->usageOf($user),
            'config' => $user->getConfig(),
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if (! empty($request->get('with_domain_names'))) {
            $resource['domain_names'] = $user->listAllDomainNames();
        }

        return $resource;
    }

    /**
     * The stored measurement; a request never runs `du`.
     *
     * @return ?array<string, mixed>
     */
    private function disk(User $user): ?array
    {
        $disk = ProjectDisk::latest((string) $user->username);
        if ($disk === null) {
            return null;
        }

        return [
            'files_bytes' => $disk['files_bytes'],
            'docker_bytes' => $disk['docker_bytes'],
            'total_bytes' => $disk['total_bytes'],
            'limit_bytes' => ResourceLimit::scaled($user->getDiskSpaceLimit(), 1024 * 1024),
            'measured_at' => $disk['measured_at'],
        ];
    }

    /**
     * The stored measurement; a request never reads the statistics.
     *
     * @return ?array<string, mixed>
     */
    private function bandwidth(User $user, Request $request): ?array
    {
        $measured = $this->measuredBandwidth($request)[(string) $user->username] ?? null;
        if ($measured === null) {
            return null;
        }

        return [
            'month' => $measured['month'],
            'used_bytes' => $measured['used_bytes'],
            'limit_bytes' => ResourceLimit::scaled($user->getBandwidthLimit(), 1024 * 1024),
            'measured_at' => $measured['measured_at'],
        ];
    }

    /** Loaded once for a whole list by UserCollection; a project shown alone loads its own. */
    private function listFacts(Request $request, User $user): ProjectListFacts
    {
        $facts = $request->attributes->get(ProjectListFacts::class);

        return $facts instanceof ProjectListFacts && $facts->covers($user) ? $facts : ProjectListFacts::load([$user]);
    }

    /**
     * Read once per request, so a project list costs one query however long it is.
     *
     * @return array<string, array{month: string, used_bytes: int, measured_at: string}>
     */
    private function measuredBandwidth(Request $request): array
    {
        if (! $request->attributes->has(ProjectBandwidth::class)) {
            $request->attributes->set(ProjectBandwidth::class, ProjectBandwidth::all());
        }

        /** @var array<string, array{month: string, used_bytes: int, measured_at: string}> */
        return $request->attributes->get(ProjectBandwidth::class);
    }

    /**
     * That the app has a login the engine generated, and where to read it. Never a value.
     *
     * @return array<string, mixed>
     */
    private function appCredentials(User $user): array
    {
        $stored = $user->getAppCredentials();

        return AppCredentials::pointer(
            $stored,
            $stored === null ? null : Networking::publicUrlOf($user),
            (string) $user->username
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publicDetails(User $user): array
    {
        $details = $user->getDetails();
        $passwordProtected = ! empty($details['site_password_enabled'])
            && is_string($details['site_password_hash'] ?? null)
            && $details['site_password_hash'] !== '';

        unset(
            $details['git_token'],
            $details['env_vars'],
            $details[User::DETAIL_VAULT_ENV_KEYS],
            $details['app_credentials'],
            $details['site_git'],
            $details['git_deploy_key'],
            $details['cloudflare_api_token'],
            $details['cloudflare_tunnel_token'],
            $details['registry_auth'],
            $details[AppDatabase::PASSWORD_DETAIL],
            $details['site_password_hash'],
            $details['site_password_enabled'],
            $details['site_password_version'],
            $details[User::DETAIL_PROTECTED],
        );

        $details['password_protection'] = $passwordProtected;

        return $details;
    }
}
