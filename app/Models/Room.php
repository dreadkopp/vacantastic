<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    /**
     * The room is keyed by its UUIDv4 (used in URLs and as the channel name).
     */
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'default_duration',
    ];

    protected $casts = [
        'default_duration' => 'int',
    ];

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'room_uuid', 'uuid');
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'room_uuid', 'uuid');
    }
}
