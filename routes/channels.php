<?php

use App\Models\Player;
use Illuminate\Support\Facades\Broadcast;

// Host-only channel: lets the host watch where players drop their pins in
// real time. Authorized via the `player-token` guard (same token as the
// REST API — X-Player-Token header or `token` query param — resolved in
// AppServiceProvider::boot()) — granted only to the room's host.
Broadcast::channel('room-{uuid}.host', function (?Player $player, string $uuid) {
    return $player !== null
        && $player->room_uuid === $uuid
        && $player->is_host;
}, ['guards' => ['player-token']]);
