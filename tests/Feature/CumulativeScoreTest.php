<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CumulativeScoreTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private Player $host;

    private Player $alice;

    private Player $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->room = Room::create(['uuid' => (string) Str::uuid(), 'default_duration' => 300]);
        $this->host = $this->room->players()->create(['name' => 'Host', 'token' => Str::random(40), 'is_host' => true]);
        $this->alice = $this->room->players()->create(['name' => 'Alice', 'token' => Str::random(40)]);
        $this->bob = $this->room->players()->create(['name' => 'Bob', 'token' => Str::random(40)]);
    }

    /**
     * Creates a round, submits the given guesses, and reveals it.
     *
     * @param  array<int, array{0: Player, 1: float, 2: float}>  $guesses
     */
    private function playRound(float $targetLat, float $targetLng, array $guesses): Round
    {
        $round = $this->room->rounds()->create([
            'target_lat' => $targetLat,
            'target_lng' => $targetLng,
            'duration' => 300,
            'status' => 'playing',
            'started_at' => now(),
            'ends_at' => now()->addMinutes(5),
        ]);

        foreach ($guesses as [$player, $lat, $lng]) {
            $round->guesses()->create([
                'player_id' => $player->id,
                'lat' => $lat,
                'lng' => $lng,
                'submitted_at' => now(),
            ]);
        }

        app(RoundService::class)->revealRound($round);

        return $round->fresh();
    }

    public function test_scores_accumulate_across_rounds_in_the_same_room(): void
    {
        // Round 1: Alice nails the target (5000 pts), Bob is way off (0 pts).
        $round1 = $this->playRound(48.0, 11.0, [
            [$this->alice, 48.0, 11.0],
            [$this->bob, -33.0, 151.0], // Sydney-ish — thousands of km away
        ]);

        $round1Results = collect($round1->results())->keyBy('id');
        $this->assertSame(5000, $round1Results[$this->alice->id]['score']);
        $this->assertSame(5000, $round1Results[$this->alice->id]['total']);
        $this->assertSame(0, $round1Results[$this->bob->id]['score']);
        $this->assertSame(0, $round1Results[$this->bob->id]['total']);

        // Round 2: both nail it — totals must now include round 1's points too.
        $round2 = $this->playRound(10.0, 20.0, [
            [$this->alice, 10.0, 20.0],
            [$this->bob, 10.0, 20.0],
        ]);

        $round2Results = collect($round2->results())->keyBy('id');
        $this->assertSame(5000, $round2Results[$this->alice->id]['score']);
        $this->assertSame(10000, $round2Results[$this->alice->id]['total']);
        $this->assertSame(5000, $round2Results[$this->bob->id]['score']);
        $this->assertSame(5000, $round2Results[$this->bob->id]['total']);

        $totals = $this->room->totalScores();
        $this->assertSame(10000, $totals->get($this->alice->id));
        $this->assertSame(5000, $totals->get($this->bob->id));
    }

    public function test_state_endpoint_surfaces_the_cumulative_total_per_player(): void
    {
        $this->playRound(48.0, 11.0, [
            [$this->alice, 48.0, 11.0],
            [$this->bob, -33.0, 151.0],
        ]);
        $this->playRound(10.0, 20.0, [
            [$this->alice, 10.0, 20.0],
            [$this->bob, 10.0, 20.0],
        ]);

        $players = $this->withHeader('X-Player-Token', $this->host->token)
            ->getJson("/api/rooms/{$this->room->uuid}/state")
            ->json('players');

        $byId = collect($players)->keyBy('id');
        $this->assertSame(10000, $byId[$this->alice->id]['totalScore']);
        $this->assertSame(5000, $byId[$this->bob->id]['totalScore']);
        $this->assertSame(0, $byId[$this->host->id]['totalScore']);
    }

    public function test_a_player_who_never_guesses_contributes_zero(): void
    {
        $round = $this->room->rounds()->create([
            'target_lat' => 48.0,
            'target_lng' => 11.0,
            'duration' => 300,
            'status' => 'playing',
            'started_at' => now(),
            'ends_at' => now()->addMinutes(5),
        ]);
        $round->guesses()->create([
            'player_id' => $this->alice->id,
            'lat' => 48.0,
            'lng' => 11.0,
            'submitted_at' => now(),
        ]);
        app(RoundService::class)->revealRound($round);

        $totals = $this->room->totalScores();
        $this->assertSame(5000, $totals->get($this->alice->id));
        $this->assertNull($totals->get($this->bob->id));
    }
}
