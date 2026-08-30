<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = [
        'room_uuid',
        'name',
        'token',
        'is_host',
    ];

    protected $casts = [
        'is_host' => 'boolean',
    ];

    /**
     * The token is the player's credential; never leak it in serialized output.
     */
    protected $hidden = [
        'token',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_uuid', 'uuid');
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }
}
