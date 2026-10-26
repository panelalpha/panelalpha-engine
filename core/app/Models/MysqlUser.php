<?php

namespace App\Models;

use App\Lib\Traits\Models\HasDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $user
 * @property ?array $details
 * @property ?array $databases
 */
class MysqlUser extends Model
{
    use HasDetails, HasFactory;

    protected $fillable = [
        'user_id',
        'user',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];
}
