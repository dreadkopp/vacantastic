<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoundFinished implements ShouldBroadcastNow
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
        return 'round.finished';
    }

    public function broadcastWith(): array
    {
        $round = $this->round;
        $results = $round->results();

        return [
            'roundId' => $round->id,
            'target' => ['lat' => $round->target_lat, 'lng' => $round->target_lng],
            'results' => $results,
            'ranking' => array_column($results, 'name'),
        ];
    }
}
