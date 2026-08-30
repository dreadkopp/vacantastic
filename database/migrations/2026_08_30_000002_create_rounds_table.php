<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->string('room_uuid', 36)->index();
            $table->string('photo_path')->nullable();
            $table->string('photo_mime')->nullable();
            $table->decimal('target_lat', 10, 7)->nullable();
            $table->decimal('target_lng', 10, 7)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('status')->default('setup');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('room_uuid')->references('uuid')->on('rooms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};
