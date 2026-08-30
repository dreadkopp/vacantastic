<?php

use App\Http\Controllers\PlayerController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoundController;
use Illuminate\Support\Facades\Route;

// Create a room (host) — no auth yet.
Route::post('/rooms', [RoomController::class, 'store']);

// Join a room — no auth yet (returns the new player's token).
Route::post('/rooms/{uuid}/join', [PlayerController::class, 'join']);

// Everything below requires a valid player token for the room.
Route::get('/rooms/{uuid}/state', [RoomController::class, 'state'])->middleware('player');
Route::patch('/rooms/{uuid}', [RoomController::class, 'update'])->middleware(['player', 'host']);
Route::get('/rooms/{uuid}/rounds/{round}/photo', [RoomController::class, 'photo'])->middleware('player');

// Host-only round management.
Route::post('/rooms/{uuid}/rounds', [RoundController::class, 'store'])->middleware(['player', 'host']);
Route::post('/rooms/{uuid}/rounds/{round}/start', [RoundController::class, 'start'])->middleware(['player', 'host']);

// Any player.
Route::post('/rooms/{uuid}/rounds/{round}/guess', [RoundController::class, 'guess'])->middleware('player');
Route::post('/rooms/{uuid}/rounds/{round}/reveal', [RoundController::class, 'reveal'])->middleware('player');
