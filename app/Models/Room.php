<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Every player's cumulative score across every round played in this
     * room so far. Rounds still in progress (score not yet set on reveal)
     * don't contribute until they're revealed.
     *
     * @return Collection<int, int> player_id => cumulative total
     */
    public function totalScores(): Collection
    {
        return Guess::query()
            ->whereHas('round', fn ($q) => $q->where('room_uuid', $this->uuid))
            ->selectRaw('player_id, SUM(score) as total')
            ->groupBy('player_id')
            ->pluck('total', 'player_id')
            ->map(fn ($total) => (int) $total);
    }
}
