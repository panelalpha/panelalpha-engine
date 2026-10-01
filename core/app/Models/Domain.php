<?php

namespace App\Models;

use App\Exceptions\NotFoundException;
use App\System;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @psalm-type DomainDetails = array{
 *   document_root?: string,
 *   redirect_enabled?: bool,
 *   redirect_url?: ?string,
 *   force_https_redirect?: bool,
 *   ssl_disabled?: bool,
 *   php_version?: string,
 *   parent_domain?: string,
 *   aliases?: array<string>,
 *   is_example_domain?: bool,
 * }
 * 
 * @property int $id
 * @property int $user_id
 * @property string $domain
 * @property string $type
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @psalm-property ?DomainDetails $details
 * @property ?User $user
 * @method static \Illuminate\Database\Eloquent\Collection<Domain> get()
 */
class Domain extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'type',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subdomains(): HasMany
    {
        return $this->hasMany(self::class, 'details->parent_domain', 'domain');
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(Tunnel::class);
    }

    public static function findByName(string $name): ?self
    {
        /** @var ?self */
        return self::query()->where('domain', $name)->first();
    }

    public static function existsByName(string $name): bool
    {
        return self::query()->where('domain', $name)->exists();
    }

    public static function findByNameOrFail(string $name): self
    {
        return self::findByName($name)
            ?? throw new NotFoundException("Domain '{$name}' not found.");
    }

    public static function findByNameOrAlias(string $domainName): ?self
    {
        /** @var ?self */
        return self::query()
            ->where('domain', $domainName)
            ->orWhereJsonContains('details->aliases', $domainName)
            ->first();
    }

    public static function domainOrAliasExists(string $domainName): bool
    {
        /** @var bool */
        $exists = self::query()
            ->where('domain', $domainName)
            ->orWhereJsonContains('details->aliases', $domainName)
            ->exists();

        return $exists || Tunnel::hostnameExists($domainName);
    }

    /**
     * @return array<Domain>
     */
    public static function getAll(): array
    {
        /** @var array<Domain> */
        return self::get()->all();
    }

    /**
     * Mark this domain as an example domain
     */
    public function setAsExampleDomain(): void
    {
        $this->setDetails(['is_example_domain' => true]);
    }

    /**
     * Check if this domain is an example domain
     */
    public function isExampleDomain(): bool
    {
        return !empty($this->getDetails()['is_example_domain']);
    }

    /**
     * Find the example domain if it exists
     */
    public static function findExampleDomain(): ?self
    {
        /** @var ?self */
        return self::query()
            ->whereJsonContains('details->is_example_domain', true)
            ->first();
    }

    public function getUser(): User
    {
        if ($this->user === null) {
            throw new \Exception("User related to domain {$this->domain} not found in database.");
        }

        return $this->user;
    }

    /**
     * @psalm-return DomainDetails
     */
    public function getDetails()
    {
        return is_null($this->details) ? [] : $this->details;
    }

    /**
     * @psalm-param DomainDetails $details
     */
    public function setDetails($details): void
    {
        $this->details = array_merge(
            $this->getDetails(),
            $details,
        );
    }

    public function projectDomain(): \App\System\Project\Domain
    {
        return $this->getUser()->project()->domain($this);
    }

    public function sslEnabled(): bool
    {
        return empty($this->getDetails()['ssl_disabled']);
    }

    public function hasTunnels(): bool
    {
        return Tunnel::domainHasTunnels($this);
    }

    public function redirectEnabled(): bool
    {
        return !empty($this->getDetails()['redirect_enabled']);
    }

    public function getRedirectUrl(): ?string
    {
        if (!$this->redirectEnabled()) {
            return null;
        }
        $details = $this->getDetails();
        if (!isset($details['redirect_url']) || $details['redirect_url'] === '') {
            return null;
        }
        return $details['redirect_url'];
    }

    public function setRedirectUrl(string $url): void
    {
        $this->setDetails([
            'redirect_enabled' => true,
            'redirect_url' => $url
        ]);
    }

    public function disableRedirect(): void
    {
        $this->setDetails([
            'redirect_enabled' => false,
            'redirect_url' => null,
        ]);
    }

    public function getDocumentRoot(): string
    {
        $details = $this->getDetails();
        if (isset($details['document_root']) && $details['document_root'] !== '') {
            return $details['document_root'];
        }
        return "/{$this->domain}/public_html";
    }

    public function setDocumentRoot(string $path): void
    {
        $this->setDetails(['document_root' => $path]);
    }

    public function forceHttpsRedirectEnabled(): bool
    {
        return !empty($this->getDetails()['force_https_redirect']);
    }

    public function setForceHttpsRedirect(bool $force): void
    {
        $this->setDetails(['force_https_redirect' => $force]);
    }

    public function getPhpVersion(): ?string
    {
        $details = $this->getDetails();
        if (isset($details['php_version']) && $details['php_version'] !== '') {
            /** @var string */
            return $details['php_version'];
        }

        $versions = (new System())->php()->listAvailablePhpVersions();
        if (!empty($versions)) {
            return reset($versions);
        }

        return null;
    }

    public function setPhpVersion(string $version): void
    {
        $this->setDetails(['php_version' => $version]);
    }

    public function findOtherDomainByNameOrAlias(string $domainName): ?self
    {
        /** @var ?self */
        return self::with('user')
            ->where('domain', '<>', $this->domain)
            ->where(function (Builder $q) use ($domainName) {
                $q->where('domain', $domainName);
                $q->orWhereJsonContains('details->aliases', $domainName);
            })->first();
    }

    public function isAliasAvailable(string $domainName): bool
    {
        if (Tunnel::hostnameExists($domainName)) {
            return false;
        }

        /** @var bool */
        return !self::query()
            ->where('domain', '<>', $this->domain)
            ->where(function (Builder $q) use ($domainName) {
                $q->where('domain', $domainName);
                $q->orWhereJsonContains('details->aliases', $domainName);
            })->exists();
    }

    /**
     * @return array<string>
     */
    public function getVhostAltNames(): array
    {
        $names = $this->getAliases();
        if ($this->domain == Setting::get('vhost-default-ip-domain')) {
            $names[] = (string)Setting::get('default_ipv4');
        }
        return $names;
    }

    /**
     * @return array<string>
     */
    public function getAliases(): array
    {
        $details = $this->getDetails();

        if (!isset($details['aliases']) || $details['aliases'] === []) {
            return [];
        }

        return $details['aliases'];
    }

    public function addAlias(string $domainName): void
    {
        if (!$this->isAliasAvailable($domainName)) {
            throw new \Exception('Domain already exists');
        }

        $details = $this->getDetails();
        if (!isset($details['aliases']) || $details['aliases'] === []) {
            $details['aliases'] = [];
        }
        if (!in_array($domainName, $details['aliases'])) {
            $details['aliases'][] = $domainName;
        }
        $this->setDetails($details);
    }

    public function removeAliases(): void
    {
        $details = $this->getDetails();
        $details['aliases'] = [];
        $this->setDetails($details);
    }
}
