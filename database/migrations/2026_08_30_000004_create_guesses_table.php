<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('round_id')->index();
            $table->unsignedBigInteger('player_id')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamp('submitted_at');
            $table->unsignedInteger('distance')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'player_id']);
            $table->foreign('round_id')->references('id')->on('rounds')->cascadeOnDelete();
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guesses');
    }
};
