<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RoundStoreTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private Player $host;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->room = Room::create(['uuid' => (string) Str::uuid(), 'default_duration' => 300]);
        $this->host = $this->room->players()->create(['name' => 'Host', 'token' => Str::random(40), 'is_host' => true]);
    }

    private function createRound(?int $duration): TestResponse
    {
        $payload = [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
            'lat' => 48.1351,
            'lng' => 11.5820,
        ];

        if ($duration !== null) {
            $payload['duration'] = $duration;
        }

        return $this->withHeader('X-Player-Token', $this->host->token)
            ->post("/api/rooms/{$this->room->uuid}/rounds", $payload);
    }

    public function test_creating_a_round_remembers_its_duration_as_the_room_default(): void
    {
        $this->createRound(120)->assertCreated();

        $this->assertSame(120, $this->room->fresh()->default_duration);
    }

    public function test_the_new_default_surfaces_in_room_state_for_the_next_round(): void
    {
        $this->createRound(600)->assertCreated();

        $room = $this->withHeader('X-Player-Token', $this->host->token)
            ->getJson("/api/rooms/{$this->room->uuid}/state")
            ->json('room');

        $this->assertSame(600, $room['defaultDuration']);
    }

    public function test_an_unlimited_round_becomes_the_new_sticky_default(): void
    {
        $this->createRound(0)->assertCreated();

        $this->assertSame(0, $this->room->fresh()->default_duration);
    }

    public function test_omitting_duration_leaves_the_room_default_untouched(): void
    {
        $this->createRound(null)->assertCreated();

        $this->assertSame(300, $this->room->fresh()->default_duration);
    }
}
