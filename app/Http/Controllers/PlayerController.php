<?php

namespace App\Http\Controllers;

use App\Events\RoomUpdated;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlayerController extends Controller
{
    /**
     * Join an existing room. Returns the new player's token.
     */
    public function join(Request $request, string $uuid)
    {
        $room = Room::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:32'],
        ]);

        $player = $room->players()->create([
            'name' => $data['name'],
            'token' => Str::random(40),
            'is_host' => false,
        ]);

        broadcast(new RoomUpdated($room));

        return response()->json([
            'token' => $player->token,
        ], 201);
    }
}
