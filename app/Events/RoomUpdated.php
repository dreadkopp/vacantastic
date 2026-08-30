<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Room $room)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('room-'.$this->room->uuid);
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    public function broadcastWith(): array
    {
        $room = $this->room;
        $round = $room->rounds()->latest('id')->first();
        $submitted = $round ? $round->guesses()->pluck('player_id')->flip()->all() : [];

        return [
            'players' => $room->players()->get()->map(fn ($p) => [
                'name' => $p->name,
                'isHost' => $p->is_host,
                'submitted' => isset($submitted[$p->id]),
            ])->all(),
            // Sanitized: no target/results. The client reloads full state on this signal.
            'round' => $round ? [
                'id' => $round->id,
                'status' => $round->status,
            ] : null,
        ];
    }
}
