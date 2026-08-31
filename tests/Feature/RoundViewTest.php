<?php

namespace Tests\Feature;

use App\Events\RoundViewUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoundViewTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private Player $host;

    private Player $player;

    private Round $round;

    protected function setUp(): void
    {
        parent::setUp();

        $this->room = Room::create(['uuid' => (string) Str::uuid(), 'default_duration' => 300]);
        $this->host = $this->room->players()->create(['name' => 'Host', 'token' => Str::random(40), 'is_host' => true]);
        $this->player = $this->room->players()->create(['name' => 'Alice', 'token' => Str::random(40)]);
        $this->round = $this->room->rounds()->create([
            'target_lat' => 48.1351,
            'target_lng' => 11.5820,
            'duration' => 300,
            'status' => 'playing',
            'started_at' => now(),
            'ends_at' => now()->addMinutes(5),
        ]);
    }

    public function test_player_can_report_their_view_while_playing(): void
    {
        Event::fake([RoundViewUpdated::class]);

        $response = $this->withHeader('X-Player-Token', $this->player->token)
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 48.1,
                'lng' => 11.5,
                'zoom' => 6,
                'widthPx' => 390,
            ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Event::assertDispatched(RoundViewUpdated::class, fn (RoundViewUpdated $e): bool =>
            $e->round->id === $this->round->id
            && $e->player->id === $this->player->id
            && abs($e->lat - 48.1) < 1e-6
            && abs($e->lng - 11.5) < 1e-6
            && $e->zoom === 6
            && $e->widthPx === 390
        );
    }

    public function test_view_is_rejected_when_the_round_is_not_playing(): void
    {
        $this->round->update(['status' => 'finished']);

        $this->withHeader('X-Player-Token', $this->player->token)
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 48.1,
                'lng' => 11.5,
                'zoom' => 6,
                'widthPx' => 390,
            ])
            ->assertStatus(409);
    }

    public function test_view_requires_a_valid_room_token(): void
    {
        $this->withHeader('X-Player-Token', 'not-a-real-token')
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 48.1,
                'lng' => 11.5,
                'zoom' => 6,
                'widthPx' => 390,
            ])
            ->assertStatus(401);
    }

    public function test_view_validates_its_payload(): void
    {
        $this->withHeader('X-Player-Token', $this->player->token)
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 91, // out of range
                'zoom' => 2, // below the usable range
            ])
            ->assertStatus(422);
    }

    public function test_host_state_includes_last_known_views(): void
    {
        // Bob joins but never reports a view — he must not appear.
        $this->room->players()->create(['name' => 'Bob', 'token' => Str::random(40)]);

        $this->withHeader('X-Player-Token', $this->player->token)
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 48.1,
                'lng' => 11.5,
                'zoom' => 6,
                'widthPx' => 390,
            ])
            ->assertOk();

        $views = $this->withHeader('X-Player-Token', $this->host->token)
            ->getJson("/api/rooms/{$this->room->uuid}/state")
            ->json('round.views');

        $this->assertCount(1, $views);
        $this->assertSame($this->player->id, $views[0]['playerId']);
        $this->assertSame('Alice', $views[0]['name']);
        $this->assertEqualsWithDelta(48.1, $views[0]['lat'], 1e-6);
        $this->assertEqualsWithDelta(11.5, $views[0]['lng'], 1e-6);
        $this->assertSame(6, $views[0]['zoom']);
        $this->assertSame(390, $views[0]['widthPx']);
    }

    public function test_regular_players_never_receive_views(): void
    {
        $this->withHeader('X-Player-Token', $this->player->token)
            ->postJson("/api/rooms/{$this->room->uuid}/rounds/{$this->round->id}/view", [
                'lat' => 48.1,
                'lng' => 11.5,
                'zoom' => 6,
                'widthPx' => 390,
            ])
            ->assertOk();

        $this->withHeader('X-Player-Token', $this->player->token)
            ->getJson("/api/rooms/{$this->room->uuid}/state")
            ->assertJsonPath('round.views', null);
    }
}
