<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Public tunnel hostname attached to a local domain (Cloudflare or PanelAlpha Online).
 *
 * @psalm-type TunnelDetails = array{
 *   cloudflare_zone_id?: string,
 *   cloudflare_dns_record_id?: string,
 *   wdns_site_id?: int,
 *   path?: string,
 *   target_ip?: string,
 *   purpose?: string,
 *   expires_at?: string,
 *   valid_until?: string,
 *   status?: string,
 * }
 *
 * @property int $id
 * @property int $domain_id
 * @property int $user_id
 * @property string $provider
 * @property string $hostname
 * @psalm-property ?TunnelDetails $details
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Domain $domain
 * @property ?User $user
 */
class Tunnel extends Model
{
    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const PROVIDER_PANELALPHA = 'panelalpha';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_CLOUDFLARE,
        self::PROVIDER_PANELALPHA,
    ];

    public const PURPOSE_DEFAULT = 'default';

    public const PURPOSE_PREVIEW = 'preview';

    /** @var list<string> */
    public const PURPOSES = [
        self::PURPOSE_DEFAULT,
        self::PURPOSE_PREVIEW,
    ];

    /** A preview tunnel with no explicit ttl_minutes lives this long. */
    public const DEFAULT_PREVIEW_TTL_MINUTES = 1440;

    protected $fillable = [
        'domain_id',
        'user_id',
        'provider',
        'hostname',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @psalm-return TunnelDetails
     */
    public function getDetails(): array
    {
        return is_null($this->details) ? [] : $this->details;
    }

    /**
     * @psalm-param TunnelDetails $details
     */
    public function setDetails(array $details): void
    {
        $this->details = array_merge($this->getDetails(), $details);
    }

    public function isCloudflare(): bool
    {
        return strtolower($this->provider) === self::PROVIDER_CLOUDFLARE;
    }

    public function isPanelAlpha(): bool
    {
        return strtolower($this->provider) === self::PROVIDER_PANELALPHA;
    }

    /** The tunnel's declared intent, `default` for a row written before the field existed. */
    public function purpose(): string
    {
        $purpose = $this->getDetails()['purpose'] ?? null;

        return is_string($purpose) && in_array($purpose, self::PURPOSES, true)
            ? $purpose
            : self::PURPOSE_DEFAULT;
    }

    /**
     * The engine-enforced lifetime in UTC, or null when the engine does not
     * expire the tunnel. The proxy's own `valid_until` is not this: only a
     * lifetime this engine wrote drives expiry.
     */
    public function expiresAt(): ?Carbon
    {
        $raw = $this->getDetails()['expires_at'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function isExpired(?Carbon $now = null): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && !$expiresAt->isAfter($now ?? Carbon::now());
    }

    /**
     * The tunnels the engine gave a lifetime that has passed, for the expiry
     * job. A tunnel carrying only the proxy's own `valid_until` is not one of
     * them; the engine never agreed to remove it.
     *
     * @return Collection<int, self>
     */
    public static function expired(): Collection
    {
        return self::query()
            ->whereNotNull('details->expires_at')
            ->get()
            ->filter(static fn (self $tunnel): bool => $tunnel->isExpired())
            ->values();
    }

    public static function findByHostname(string $hostname): ?self
    {
        /** @var ?self */
        return self::query()->where('hostname', strtolower($hostname))->first();
    }

    public static function hostnameExists(string $hostname): bool
    {
        return self::query()->where('hostname', strtolower($hostname))->exists();
    }

    /**
     * @return list<self>
     */
    public static function forDomain(Domain $domain): array
    {
        /** @var list<self> */
        return self::query()
            ->where('domain_id', $domain->id)
            ->orderBy('hostname')
            ->get()
            ->all();
    }

    public static function domainHasTunnels(Domain $domain): bool
    {
        return self::query()->where('domain_id', $domain->id)->exists();
    }

    public static function projectHasTunnels(User $user): bool
    {
        return self::query()->where('user_id', $user->id)->exists();
    }

    public static function projectHasCloudflareTunnels(User $user): bool
    {
        return self::query()
            ->where('user_id', $user->id)
            ->where('provider', self::PROVIDER_CLOUDFLARE)
            ->exists();
    }
}
