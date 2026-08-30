<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoundStarted implements ShouldBroadcastNow
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
        return 'round.started';
    }

    public function broadcastWith(): array
    {
        $round = $this->round;

        return [
            'roundId' => $round->id,
            'duration' => $round->effectiveDuration(),
            // Base URL only — the client appends its own token (this is a shared broadcast).
            'photoUrl' => $round->photo_path
                ? url("/api/rooms/{$round->room_uuid}/rounds/{$round->id}/photo")
                : null,
            'total' => $round->room->players()->count(),
        ];
    }
}
