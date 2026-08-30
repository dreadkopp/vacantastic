<?php

namespace App\Services;

use App\Events\RoomUpdated;
use App\Events\RoundFinished;
use App\Events\RoundGuessSubmitted;
use App\Events\RoundStarted;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use Illuminate\Http\Exceptions\HttpException;

class RoundService
{
    public function __construct(protected GeoService $geo)
    {
    }

    /**
     * Start a round from the setup state. No-op unless the round is in setup.
     */
    public function startRound(Round $round): void
    {
        if ($round->status !== 'setup') {
            return;
        }

        $round->status = 'playing';
        $round->started_at = now();
        $duration = $round->effectiveDuration();
        $round->ends_at = $duration > 0 ? now()->addSeconds($duration) : null;
        $round->save();

        broadcast(new RoundStarted($round));
        broadcast(new RoomUpdated($round->room));
    }

    /**
     * Record (or update) a player's single guess for the round.
     * Reveals the round as soon as every player has submitted.
     */
    public function submitGuess(Round $round, Player $player, float $lat, float $lng): Guess
    {
        if ($round->status !== 'playing') {
            throw new HttpException(409, 'This round is not accepting guesses.');
        }

        // The host chose the location, so they don't compete.
        if ($player->is_host) {
            throw new HttpException(403, 'The host set the location and does not guess.');
        }

        $guess = $round->guesses()->updateOrCreate(
            ['player_id' => $player->id],
            [
                'lat' => $lat,
                'lng' => $lng,
                'submitted_at' => now(),
            ],
        );

        broadcast(new RoundGuessSubmitted($round));

        // Reveal once every non-host player has submitted (the host never does).
        $guessers = $round->room->players()->where('is_host', false)->count();
        if ($guessers > 0 && $round->guesses()->count() >= $guessers) {
            $this->revealRound($round);
        }

        return $guess;
    }

    /**
     * Reveal the round: score every player, then mark it finished. Idempotent.
     */
    public function revealRound(Round $round): void
    {
        if ($round->status === 'finished') {
            return;
        }

        $hasTarget = $round->target_lat !== null && $round->target_lng !== null;
        $guesses = $round->guesses()->get()->keyBy('player_id');

        foreach ($round->room->players as $player) {
            $guess = $guesses->get($player->id);

            if (! $guess) {
                continue; // No guess row; the player scores 0 (reflected in results()).
            }

            if ($hasTarget) {
                $distance = $this->geo->distanceMeters(
                    $round->target_lat,
                    $round->target_lng,
                    $guess->lat,
                    $guess->lng,
                );
                $guess->distance = (int) round($distance);
                $guess->score = $this->geo->scoreForDistance($distance);
            } else {
                $guess->distance = null;
                $guess->score = 0;
            }

            $guess->save();
        }

        $round->status = 'finished';
        $round->save();

        broadcast(new RoundFinished($round));
        broadcast(new RoomUpdated($round->room));
    }

    /**
     * Reveal every playing round whose time limit has elapsed. Returns the count.
     */
    public function revealExpired(): int
    {
        $expired = Round::query()
            ->where('status', 'playing')
            ->where('ends_at', '<', now())
            ->get();

        foreach ($expired as $round) {
            $this->revealRound($round);
        }

        return $expired->count();
    }
}
