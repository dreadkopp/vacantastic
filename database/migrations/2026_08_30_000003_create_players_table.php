<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('room_uuid', 36)->index();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->boolean('is_host')->default(false);
            $table->timestamps();

            $table->foreign('room_uuid')->references('uuid')->on('rooms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
