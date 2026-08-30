<?php

namespace App\Http\Controllers;

use App\Events\RoomUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Services\RoundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    /**
     * Create a room and its host. Returns the host's token + share URL.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32'],
        ]);

        $room = Room::create([
            'uuid' => (string) Str::uuid(),
            'default_duration' => 300,
        ]);

        $host = $room->players()->create([
            'name' => $data['name'],
            'token' => Str::random(40),
            'is_host' => true,
        ]);

        broadcast(new RoomUpdated($room));

        return response()->json([
            'uuid' => $room->uuid,
            'token' => $host->token,
            'shareUrl' => url('/r/'.$room->uuid),
        ], 201);
    }

    /**
     * Full game state for the requesting player.
     */
    public function state(Request $request, string $uuid, RoundService $rounds)
    {
        $room = Room::where('uuid', $uuid)->firstOrFail();
        $player = $request->attributes->get('player');

        $round = $room->rounds()->latest('id')->first();

        // Lazy expiry: reveal a playing round whose clock has run out.
        if ($round && $round->status === 'playing' && $round->ends_at?->isPast()) {
            $rounds->revealRound($round);
            $round->refresh();
        }

        return response()->json($this->stateJson($room, $round, $player));
    }

    /**
     * Host updates the room's default per-round time limit.
     */
    public function update(Request $request, string $uuid)
    {
        $room = Room::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'default_duration' => ['required', 'integer', 'min:30', 'max:3600'],
        ]);

        $room->update($data);

        return response()->json($room);
    }

    /**
     * Stream the round's private photo to a validated room member.
     */
    public function photo(Request $request, string $uuid, Round $round)
    {
        abort_unless($round->room_uuid === $uuid, 404);
        abort_if(! $round->photo_path, 404);

        $contents = Storage::disk('local')->get($round->photo_path);

        return response($contents, 200, [
            'Content-Type' => $round->photo_mime ?? 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function stateJson(Room $room, ?Round $round, Player $player): array
    {
        $submitted = $round
            ? $round->guesses()->pluck('player_id')->flip()->all()
            : [];

        $players = $room->players()->get()->map(fn (Player $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'isHost' => $p->is_host,
            'submitted' => isset($submitted[$p->id]),
            'you' => $p->id === $player->id,
        ])->all();

        return [
            'room' => [
                'uuid' => $room->uuid,
                'defaultDuration' => $room->default_duration,
            ],
            'players' => $players,
            'round' => $round ? $this->roundJson($room, $round, $player) : null,
        ];
    }

    protected function roundJson(Room $room, Round $round, Player $player): array
    {
        $finished = $round->status === 'finished';
        $myGuess = $round->guesses()->where('player_id', $player->id)->first();

        return [
            'id' => $round->id,
            'status' => $round->status,
            'duration' => $round->effectiveDuration(),
            'endsAt' => $round->ends_at?->toIso8601String(),
            'photoUrl' => $round->photo_path
                ? url("/api/rooms/{$room->uuid}/rounds/{$round->id}/photo?token={$player->token}")
                : null,
            // Target + results are only exposed once the round is finished.
            'target' => $finished
                ? ['lat' => $round->target_lat, 'lng' => $round->target_lng]
                : null,
            'results' => $finished ? $round->results() : null,
            'myGuess' => $myGuess ? ['lat' => $myGuess->lat, 'lng' => $myGuess->lng] : null,
        ];
    }
}
