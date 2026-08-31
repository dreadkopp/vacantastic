<?php

namespace App\Http\Controllers;

use App\Events\RoomUpdated;
use App\Events\RoundViewUpdated;
use App\Models\Room;
use App\Models\Round;
use App\Services\RoundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        // Remember this round's time limit as the room's new default, so the
        // next round's host panel starts pre-selected to whatever was just used.
        if (array_key_exists('duration', $data)) {
            $room->update(['default_duration' => $data['duration']]);
        }

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
     * A player reports where they are currently looking on the map (center + zoom).
     * The host watches these as live circles; regular players never receive them.
     */
    public function view(Request $request, string $uuid, Round $round)
    {
        abort_unless($round->room_uuid === $uuid, 404);

        if ($round->status !== 'playing') {
            throw new HttpException(409, 'This round is not playing.');
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'zoom' => ['required', 'integer', 'between:3,20'],
            'widthPx' => ['required', 'integer', 'between:1,4096'],
        ]);

        $player = $request->attributes->get('player');

        // The client throttles to ~1 report/second; this only guards against a
        // runaway client flooding the host's channel.
        $limit = "vacantastic:view:{$player->id}:{$round->id}";
        if (RateLimiter::tooManyAttempts($limit, 120)) {
            throw new HttpException(429, 'Too many view updates.');
        }
        RateLimiter::hit($limit, 60);

        // Remember the last known view so a host who refreshes (or re-renders)
        // mid-round can restore the circles. Ephemeral — expires on its own.
        Cache::put(
            "vacantastic:view:{$round->id}:{$player->id}",
            ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'zoom' => (int) $data['zoom'], 'widthPx' => (int) $data['widthPx']],
            now()->addMinutes(30),
        );

        broadcast(new RoundViewUpdated($round, $player, (float) $data['lat'], (float) $data['lng'], (int) $data['zoom'], (int) $data['widthPx']));

        return response()->json(['ok' => true]);
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
