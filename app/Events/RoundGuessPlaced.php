<?php

namespace App\Events;

use App\Models\Guess;
use App\Models\Round;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Host-only live view of where players drop their pins.
 *
 * Broadcast on a private channel that only the room's host can subscribe to
 * (see routes/channels.php). Regular players never receive coordinates —
 * the public RoundGuessSubmitted event carries only the tally (anti-cheat).
 */
class RoundGuessPlaced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Round $round, public Guess $guess)
    {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room-'.$this->round->room_uuid.'.host');
    }

    public function broadcastAs(): string
    {
        return 'round.guess-placed';
    }

    public function broadcastWith(): array
    {
        return [
            'roundId' => $this->round->id,
            'playerId' => $this->guess->player_id,
            'name' => $this->guess->player->name,
            'lat' => $this->guess->lat,
            'lng' => $this->guess->lng,
            'submitted' => $this->round->guesses()->count(),
            'total' => $this->round->room->players()->where('is_host', false)->count(),
        ];
    }
}
