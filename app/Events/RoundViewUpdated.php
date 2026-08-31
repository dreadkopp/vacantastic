<?php

namespace App\Events;

use App\Models\Player;
use App\Models\Round;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Host-only live view of where each player is currently looking on the map.
 *
 * Players report their map center + zoom while guessing; the host renders a
 * circle around each player's viewport. Broadcast on the same private channel
 * as RoundGuessPlaced — regular players never receive it (anti-cheat).
 */
class RoundViewUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Round $round,
        public Player $player,
        public float $lat,
        public float $lng,
        public int $zoom,
        public int $widthPx,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('room-'.$this->round->room_uuid.'.host');
    }

    public function broadcastAs(): string
    {
        return 'round.view-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'roundId' => $this->round->id,
            'playerId' => $this->player->id,
            'name' => $this->player->name,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'zoom' => $this->zoom,
            'widthPx' => $this->widthPx,
        ];
    }
}
