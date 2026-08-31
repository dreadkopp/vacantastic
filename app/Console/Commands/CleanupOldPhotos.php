<?php

namespace App\Console\Commands;

use App\Services\RoundService;
use Illuminate\Console\Command;

class CleanupOldPhotos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vacantastic:cleanup-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete photo uploads of outdated rounds (finished or unlimited, older than a week)';

    public function handle(RoundService $rounds): int
    {
        $count = $rounds->cleanupOldPhotos();

        if ($count > 0) {
            $this->info("Removed {$count} outdated photo upload(s).");
        }

        return self::SUCCESS;
    }
}
