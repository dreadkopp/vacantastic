<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoundGuessSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Round $round)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('room-'.$this->round->room_uuid);
    }

    public function broadcastAs(): string
    {
        return 'round.guess-submitted';
    }

    public function broadcastWith(): array
    {
        // No coordinates — anti-cheat. Just the running tally.
        return [
            'roundId' => $this->round->id,
            'submitted' => $this->round->guesses()->count(),
            'total' => $this->round->room->players()->where('is_host', false)->count(),
        ];
    }
}
