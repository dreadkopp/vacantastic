<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guess extends Model
{
    protected $fillable = [
        'round_id',
        'player_id',
        'lat',
        'lng',
        'submitted_at',
        'distance',
        'score',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'distance' => 'int',
        'score' => 'int',
        'submitted_at' => 'datetime',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
