<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An archive uploaded before the project it is for exists. `claimed_by` is
 * the project a create or attach gave it to; until then any create may name it.
 *
 * @property string $id
 * @property string $filename
 * @property int $size
 * @property string $path relative to the uploads disk root
 * @property ?string $claimed_by
 * @property ?int $created_by_token_id
 * @property Carbon $expires_at
 * @property ?Carbon $created_at
 */
class Upload extends Model
{
    public const PREFIX = 'upl_';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'filename',
        'size',
        'path',
        'claimed_by',
        'created_by_token_id',
        'expires_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'created_by_token_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'size' => $this->size,
            'claimed_by' => $this->claimed_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
