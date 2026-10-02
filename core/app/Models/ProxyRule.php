<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @psalm-type ProxyRuleMetadata = array{
 *   description?: string,
 *   source?: string,
 *   original_port?: int,
 * }
 *
 * @property int $id
 * @property string $owner_scope
 * @property ?string $username
 * @property bool $enabled
 * @property string $transport
 * @property ?string $listen_ip
 * @property int $listen_port
 * @property ?string $server_name
 * @property string $upstream_host
 * @property int $upstream_port
 * @property ?string $upstream_protocol
 * @property bool $is_generated
 * @property ?ProxyRuleMetadata $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?User $user
 * @method static ?ProxyRule find(int $id)
 */
class ProxyRule extends Model
{
    protected $fillable = [
        'owner_scope',
        'username',
        'enabled',
        'transport',
        'listen_ip',
        'listen_port',
        'server_name',
        'upstream_host',
        'upstream_port',
        'upstream_protocol',
        'is_generated',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_generated' => 'boolean',
        'listen_port' => 'integer',
        'upstream_port' => 'integer',
        'metadata' => 'array',
    ];

    /** @return array<ProxyRule> */
    public static function getEnabled(): array
    {
        /** @var Collection<array-key, ProxyRule> */
        $query = self::query()
            ->where('enabled', true)
            ->orderBy('owner_scope', 'asc')  // System rules first
            ->orderBy('created_at', 'asc')   // Then by creation order
            ->get();

        return $query->all();
    }

    /**
     * Get the user associated with this proxy rule (for user-owned rules).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'username', 'username');
    }

    /**
     * Scope to get only system-owned rules.
     */
    public function scopeSystemOwned(Builder $query): Builder
    {
        return $query->where('owner_scope', 'system');
    }

    /**
     * Scope to get only user-owned rules.
     */
    public function scopeUserOwned(Builder $query): Builder
    {
        return $query->where('owner_scope', 'user');
    }

    /**
     * Scope to get rules for a specific user.
     */
    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->where('owner_scope', 'user')->where('username', $username);
    }

    /**
     * Remove user-scoped rules that were never linked to a username.
     * Older panel creates omitted username, so FK cascade / forUser() could not clean them.
     */
    public static function pruneOrphanUserRules(): int
    {
        return self::query()
            ->where('owner_scope', 'user')
            ->whereNull('username')
            ->delete();
    }

    /**
     * Move this project's proxy rules from one FQDN to another (domain rename).
     * Also retargets www.$from → www.$to when such rows exist.
     *
     * @return int Number of rows updated
     */
    public static function retargetServerName(string $username, string $from, string $to): int
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return 0;
        }

        $updated = self::forUser($username)
            ->where('server_name', $from)
            ->update(['server_name' => $to]);

        $wwwFrom = 'www.' . $from;
        $wwwTo = 'www.' . $to;
        $updated += self::forUser($username)
            ->where('server_name', $wwwFrom)
            ->update(['server_name' => $wwwTo]);

        return $updated;
    }

    /**
     * Ensure generated HTTP :80 and :443 rules exist for a domain → account upstream.
     */
    public static function ensureGeneratedHttpPair(string $username, string $fqdn, int $upstreamPort): void
    {
        self::upsertGeneratedHttpRule($username, $fqdn, 80, $upstreamPort, true);
        self::upsertGeneratedHttpRule($username, $fqdn, 443, $upstreamPort, true);
    }

    /**
     * Upsert the domain's generated :80/:443 pair in place and drop every other
     * generated row of the user (legacy *:appPort rows, a previous domain).
     */
    public static function syncGeneratedHttpPair(string $username, string $fqdn, int $upstreamPort): void
    {
        $keep = [];
        foreach ([80, 443] as $listenPort) {
            $rule = self::upsertGeneratedHttpRule($username, $fqdn, $listenPort, $upstreamPort, true);
            if ($rule !== null) {
                $keep[] = $rule->id;
            }
        }

        self::forUser($username)->where('is_generated', true)->whereNotIn('id', $keep)->delete();
    }

    /**
     * Persist one generated HTTP listen → upstream rule for a project domain.
     * Returns null when a hand-made rule already owns that domain and port.
     */
    public static function upsertGeneratedHttpRule(
        string $username,
        string $fqdn,
        int $listenPort,
        int $upstreamPort,
        bool $isPrimary = false
    ): ?self {
        // The domain vhost renders one rule per port, and an operator's rule beats the default.
        $handMade = self::query()
            ->where('transport', 'http')
            ->where('listen_port', $listenPort)
            ->where('server_name', $fqdn)
            ->where('is_generated', false)
            ->exists();
        if ($handMade) {
            return null;
        }

        $key = [
            'owner_scope' => 'user',
            'username' => $username,
            'transport' => 'http',
            'listen_port' => $listenPort,
            'server_name' => $fqdn,
        ];
        /** @var self $rule */
        $rule = self::query()->where($key)->where('is_generated', true)->first() ?? new self($key);
        $rule->fill([
            'enabled' => true,
            'listen_ip' => '*',
            'upstream_host' => $username,
            'upstream_port' => $upstreamPort,
            'is_generated' => true,
            'metadata' => [
                'description' => ($isPrimary ? 'Primary' : 'Additional')
                    . " {$listenPort}→{$upstreamPort} for {$username}",
                'source' => 'auto-detected-from-compose',
                'detected_port' => $upstreamPort,
            ],
        ]);
        // Nothing in a deploy declares the protocol, so an operator's switch to https stays.
        if (!$rule->exists || $rule->upstream_protocol === null) {
            $rule->upstream_protocol = 'http';
        }
        $rule->save();

        return $rule;
    }

    /**
     * Scope to get only enabled rules.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to get HTTP rules.
     */
    public function scopeHttp(Builder $query): Builder
    {
        return $query->where('transport', 'http');
    }

    /**
     * Scope to get stream rules (TCP/UDP).
     */
    public function scopeStream(Builder $query): Builder
    {
        return $query->whereIn('transport', ['tcp', 'udp']);
    }
}
