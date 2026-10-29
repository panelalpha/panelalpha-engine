<?php

namespace App\Models;

use App\Lib\Traits\Models\HasDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MysqlSsoUser extends Model
{
    use HasDetails, HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];
}
