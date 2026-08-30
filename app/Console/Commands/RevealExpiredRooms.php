<?php

namespace App\Console\Commands;

use App\Services\RoundService;
use Illuminate\Console\Command;

class RevealExpiredRooms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vacantastic:reveal-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reveal any playing rounds whose time limit has elapsed';

    public function handle(RoundService $rounds): int
    {
        $count = $rounds->revealExpired();

        if ($count > 0) {
            $this->info("Revealed {$count} expired round(s).");
        }

        return self::SUCCESS;
    }
}
