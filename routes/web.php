<?php

use App\Models\Room;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

Route::get('/r/{uuid}', function (string $uuid) {
    abort_unless(Room::where('uuid', $uuid)->exists(), 404);
    return view('room', ['uuid' => $uuid]);
})->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
