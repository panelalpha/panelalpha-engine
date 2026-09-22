<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * The address and secret a git host calls when its repository is pushed to,
 * for one checkout of one project.
 *
 * @property int     $id
 * @property int     $user_id
 * @property string  $path_key
 * @property string  $public_id
 * @property ?string $registered_url
 * @property string  $secret_encrypted
 * @property ?int    $pending_delivery_id
 * @property Carbon  $created_at
 * @property Carbon  $updated_at
 * @property ?User   $user
 * @property ?HookDelivery $pendingDelivery
 */
class DeployHook extends Model
{
    protected $fillable = [
        'user_id',
        'path_key',
        'public_id',
        'registered_url',
        'secret_encrypted',
        'pending_delivery_id',
    ];

    /**
     * A fresh URL segment. 160 random bits, hex: long enough that guessing
     * one is not a strategy, plain enough to paste anywhere.
     */
    public static function newPublicId(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * A fresh secret. Shown once, at creation, and never again.
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function setSecret(string $secret): void
    {
        $this->secret_encrypted = Crypt::encryptString($secret);
    }

    /**
     * The plaintext, for checking a signature. Null only for a ciphertext
     * that no longer decrypts (an APP_KEY change); the caller treats that as
     * "nothing verifies", which is the safe reading.
     */
    public function secret(): ?string
    {
        try {
            return Crypt::decryptString($this->secret_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The URL to register with the git host: the engine's own address, then
     * the opaque id. Nothing about the project is in it.
     */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/') . '/hooks/' . $this->public_id;
    }

    /**
     * Whether the engine's own address has moved on from the one this hook
     * was last registered under -- an IP a domain certificate then replaced,
     * for instance. A hook created before this was tracked (`registered_url`
     * null) reports no drift: there is nothing to compare against.
     */
    public function addressChanged(): bool
    {
        return $this->registered_url !== null && $this->registered_url !== $this->url();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(HookDelivery::class);
    }

    /**
     * The delivery coalescing is waiting to run once this hook's project
     * finishes the deploy it arrived during, or null when nothing is pending.
     */
    public function pendingDelivery(): BelongsTo
    {
        return $this->belongsTo(HookDelivery::class, 'pending_delivery_id');
    }
}
