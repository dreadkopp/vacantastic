<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    protected $fillable = [
        'room_uuid',
        'photo_path',
        'photo_mime',
        'target_lat',
        'target_lng',
        'duration',
        'status',
        'started_at',
        'ends_at',
    ];

    protected $casts = [
        'target_lat' => 'float',
        'target_lng' => 'float',
        'duration' => 'int',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_uuid', 'uuid');
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }

    /**
     * The per-round time limit, falling back to the room default.
     */
    public function effectiveDuration(): int
    {
        return $this->duration ?? $this->room->default_duration;
    }

    /**
     * Every player's result for this round, sorted by score (desc).
     *
     * Players who never guessed appear with null coordinates and a score of 0.
     * Only meaningful once the round is finished (distance/score are set on reveal).
     *
     * @return array<int, array{id: int, name: string, lat: ?float, lng: ?float, distance: ?int, score: int}>
     */
    public function results(): array
    {
        $guesses = $this->guesses()->get()->keyBy('player_id');

        return $this->room->players
            ->reject(fn (Player $p) => $p->is_host)
            ->map(function (Player $player) use ($guesses): array {
                $guess = $guesses->get($player->id);

                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'lat' => $guess?->lat,
                    'lng' => $guess?->lng,
                    'distance' => $guess?->distance,
                    'score' => $guess?->score ?? 0,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->all();
    }
}
