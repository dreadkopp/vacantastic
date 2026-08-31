<?php

namespace App\Http\Controllers;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\Round;
use App\Services\RoundService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoundController extends Controller
{
    public function __construct(protected RoundService $rounds)
    {
    }

    /**
     * Host uploads a photo + target to create a new round (status: setup).
     */
    public function store(Request $request, string $uuid)
    {
        $room = Room::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:10240'], // 10 MB
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'duration' => ['sometimes', 'integer', 'min:0', 'max:3600'],
        ]);

        $file = $request->file('photo');
        $photoPath = $file->storeAs(
            'photos/'.$uuid,
            Str::uuid().'.'.$file->extension(),
            'local',
        );

        $round = $room->rounds()->create([
            'photo_path' => $photoPath,
            'photo_mime' => $file->getClientMimeType(),
            'target_lat' => $data['lat'],
            'target_lng' => $data['lng'],
            'duration' => $data['duration'] ?? null,
            'status' => 'setup',
        ]);

        broadcast(new RoomUpdated($room));

        return response()->json($round, 201);
    }

    /**
     * Host starts the round.
     */
    public function start(Request $request, string $uuid, Round $round)
    {
        abort_unless($round->room_uuid === $uuid, 404);

        $this->rounds->startRound($round);

        return response()->json($round->refresh());
    }

    /**
     * Any player submits (or updates) their pin.
     */
    public function guess(Request $request, string $uuid, Round $round)
    {
        abort_unless($round->room_uuid === $uuid, 404);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $player = $request->attributes->get('player');
        $this->rounds->submitGuess($round, $player, (float) $data['lat'], (float) $data['lng']);

        return response()->json([
            'submitted' => $round->guesses()->count(),
            'total' => $round->room->players()->count(),
        ]);
    }

    /**
     * Any player (or the timer) reveals the round early.
     */
    public function reveal(Request $request, string $uuid, Round $round)
    {
        abort_unless($round->room_uuid === $uuid, 404);

        $this->rounds->revealRound($round);

        $round->refresh();

        return response()->json([
            'round' => $round,
            'results' => $round->results(),
        ]);
    }
}
