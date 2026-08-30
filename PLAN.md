# Vacantastic — Vacation Photo Geo-Guessing (Laravel 13 + Reverb, Docker + Traefik)

## Context

Build **Vacantastic**, a GeoGuessr/GeoTastic-style online multiplayer game. The spin: instead of a random Street-View location, the **host uploads a vacation photo** and its coordinates (auto-extracted from the photo's EXIF GPS when present, otherwise entered manually). Other players join via a **shareable link**, look at the photo, and place a **pin on a map** where they think it was taken. When the round ends, the true location and all pins are revealed; the player **closest to the true location wins** (GeoGuessr-style scoring).

Runtime: **Laravel 13 on PHP 8.5**, real-time via **Laravel Reverb** (WebSocket), **SQLite** storage, **Docker** (4 services), fronted by the user's existing **Traefik** reverse proxy (TLS + WS upgrade). Frontend is **Blade + vanilla JS** with CDN libraries (Leaflet/OSM map, exifr EXIF, pusher-js WebSocket) — no Node/Vite build. Hosted online; **photos are private** (room-members only).

## Stack & Architecture

- **Backend**: Laravel 13, PHP 8.5. Eloquent models, API routes, Reverb broadcasting (`ShouldBroadcastNow` — no queue worker).
- **Real-time**: Laravel Reverb (ReactPHP WS server, no Swoole). Public room channel `room-{uuid}`. Client uses `pusher-js` (Reverb is Pusher-protocol compatible). Reached via Traefik at `wss://<domain>/app`.
- **Map**: Leaflet 1.9.4 + OpenStreetMap tiles (no API key).
- **EXIF**: exifr 7 (CDN UMD → `window.exifr`; `exifr.gps(file)` → `{latitude, longitude}` or `undefined`).
- **Storage**: SQLite (WAL mode). Photos on the `local` (private) disk — never web-exposed directly.
- **HTTP/WS edge**: the user's **Traefik** proxy (TLS termination + automatic WebSocket upgrade). No nginx, no php-fpm. The app runs `php artisan serve`; upgrade path to Octane later.
- **Docker** (4 services): `app` (`php artisan serve`), `reverb` (`php artisan reverb:start`), `cache` (KeyDB), `scheduler` (`php artisan schedule:work`).

### Identity & access model
- **Room** = a **UUIDv4** (`Str::uuid()`), used in URLs and as the channel name.
- **Player** = a row with a random **token** (stored in the browser `localStorage`, keyed by room uuid). Sent to the API via the `X-Player-Token` header (or `?token=` for the photo `<img>`). No user accounts, no Sanctum, no sessions.
- **Photos** are private: stored on the private disk and served only to validated room members (token check). The UUIDv4 makes rooms unguessable; the membership check is the real gate.
- **Target coordinates** are never exposed until the round is `finished`.

## Approach

### 1. Scaffold Laravel 13
In `/Users/arne/Documents/projects` (the `vacantastic/` folder exists and is empty): `composer create-project laravel/laravel:^13.0 vacantastic` (Composer allows an empty target dir). Requires local PHP ≥ 8.3 (8.5 preferred). This yields the standard Laravel 13 skeleton (slim `bootstrap/app.php`, `routes/web.php`, `routes/console.php`, `config/`, `app/`, `public/`).

### 2. Install Reverb
`composer require laravel/reverb` then `php artisan reverb:install` (publishes `config/reverb.php`, adds `REVERB_*` env vars, sets `BROADCAST_CONNECTION=reverb`). Do **not** run the npm/Echo scaffolding (the client uses raw `pusher-js` via CDN, not the npm `laravel-echo`). In `config/reverb.php`, set `apps[0].allowed_origins = ['*']`, `server.host = '0.0.0.0'`, `server.port = 8080`.

### 3. Data model
Create 4 migrations + models. Exact columns:

- **`rooms`**: `uuid` (string 36, **primary key**), `default_duration` (unsignedInteger, default 300 — seconds; the "time limit per round" setting), `created_at`, `updated_at`.
- **`rounds`**: `id` (bigIncrements), `room_uuid` (string 36, FK→rooms.uuid, indexed), `photo_path` (string, nullable), `photo_mime` (string, nullable), `target_lat` (decimal 10,7, nullable), `target_lng` (decimal 10,7, nullable), `duration` (unsignedInteger, nullable — per-round override), `status` (string, default `'setup'` — `setup`|`playing`|`finished`), `started_at` (timestamp, nullable), `ends_at` (timestamp, nullable), `created_at`, `updated_at`.
- **`players`**: `id` (bigIncrements), `room_uuid` (string 36, FK→rooms.uuid, indexed), `name` (string), `token` (string 64, **unique**), `is_host` (boolean, default false), `created_at`, `updated_at`.
- **`guesses`**: `id` (bigIncrements), `round_id` (bigInteger, FK→rounds.id, indexed), `player_id` (bigInteger, FK→players.id, indexed), `lat` (decimal 10,7), `lng` (decimal 10,7), `submitted_at` (timestamp), `distance` (unsignedInteger, nullable — meters, set on reveal), `score` (unsignedInteger, nullable — set on reveal), `created_at`, `updated_at`; **unique(`round_id`, `player_id`)**.

Models (`app/Models/`):
- `Room` (table `rooms`, PK `uuid`): `rounds()` hasMany, `players()` hasMany. Cast `default_duration`→int.
- `Round`: `room()` belongsTo(Room::class, 'uuid', 'room_uuid'), `guesses()` hasMany. Casts: `target_lat`/`target_lng`→float, `duration`→int, `started_at`/`ends_at`→datetime. Helper `effectiveDuration(): int` → `$this->duration ?? $this->room->default_duration`.
- `Player`: `room()` belongsTo(Room::class, 'uuid', 'room_uuid'), `guesses()` hasMany. Cast `is_host`→bool.
- `Guess`: `round()` belongsTo, `player()` belongsTo. Casts: `lat`/`lng`→float, `distance`/`score`→int.

Run `php artisan migrate` (creates `database/database.sqlite`).

### 4. GeoService
Create `app/Services/GeoService.php`:
- `distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float` — haversine, Earth radius 6371000 m.
- `scoreForDistance(float $meters): int` — `max(0, (int) round(5000 * exp(-$meters / 1609)))` (GeoGuessr formula).

### 5. RoundService (state machine)
Create `app/Services/RoundService.php` (inject `GeoService`). All methods guard on `status`; `revealRound` is idempotent:
- `startRound(Round $round): void` — only from `setup`; set `status=playing`, `started_at=now`, `ends_at=now+effectiveDuration()`; `save`; `broadcast(new RoundStarted($round))`; `broadcast(new RoomUpdated($round->room))`.
- `submitGuess(Round $round, Player $player, float $lat, float $lng): Guess` — only while `playing`; upsert the player's single guess for the round; `broadcast(new RoundGuessSubmitted($round))`; **if `guesses()->count() === players()->count()` → `revealRound($round)`**.
- `revealRound(Round $round): void` — only if `status !== 'finished'`; for every player: if they have a guess, set `distance` (haversine to target) + `score`; else `distance=null, score=0`. Set `status=finished`, `save`; `broadcast(new RoundFinished($round))`; `broadcast(new RoomUpdated($round->room))`.
- `revealExpired(): int` — `Round::where('status','playing')->where('ends_at','<',now())->get()`; `revealRound` each; return the count. (Scheduler safety net + lazy check.)

### 6. Player-token auth middleware
Create `app/Http/Middleware/EnsurePlayerToken.php`:
- Read the room `uuid` from the route (`$route->parameter('uuid')`) and the token from the `X-Player-Token` header **or** the `token` query param.
- Look up `Player::where('room_uuid',$uuid)->where('token',$token)->first()`; if missing → `abort(401)`.
- Attach via `$request->attributes->set('player', $player)`; controllers read `$request->attributes->get('player')`.
- Register alias `player` in `bootstrap/app.php`: `->withMiddleware(fn (Middleware $m) => $m->alias(['player' => EnsurePlayerToken::class]))`.
- Also add a `host` middleware (same file or `EnsureHost.php`): after `player`, check `$request->attributes->get('player')->is_host` else `abort(403)`. Alias `host`.

### 7. API controllers + routes
`app/Http/Controllers/` — all in `routes/api.php`:

- `RoomController@store(Request)`: validate `name` (required, ≤32). Create Room (`uuid=Str::uuid()`, `default_duration=300`) + host Player (`token=Str::random(40)`, `is_host=true`). `broadcast(new RoomUpdated($room))`. Return `{uuid, token, shareUrl: url("/r/{$uuid}")}`.
- `RoomController@state(Request, $uuid)` `[player]`: if the current round is `playing` and `ends_at < now`, call `RoundService::revealRound` first (lazy expiry). Return the state JSON (§8).
- `RoomController@update(Request, $uuid)` `[player, host]`: validate `default_duration` (int 30..3600); update; return the room.
- `RoomController@photo(Request, $uuid, Round $round)` `[player]`: `abort_unless($round->room_uuid === $uuid, 404)`; stream the photo from the `local` disk with `photo_mime`. (Token via query for `<img>`.)
- `PlayerController@join(Request, $uuid)`: validate `name` (≤32). Create Player (`token=Str::random(40)`, `is_host=false`). `broadcast(new RoomUpdated($room))`. Return `{token}`.
- `RoundController@store(Request, $uuid)` `[player, host]`: validate `photo` (required image, ≤10MB), `lat` (-90..90), `lng` (-180..180), `duration` (nullable int 30..3600). Store photo on `local` disk at `photos/{uuid}/{Str::uuid()}.{ext}` (keep original mime). Create Round (`status=setup`, target, duration). `broadcast(new RoomUpdated($room))`. Return the round.
- `RoundController@start(Request, $uuid, Round $round)` `[player, host]`: `abort_unless($round->room_uuid === $uuid, 404)`; `RoundService::startRound`. Return the round.
- `RoundController@guess(Request, $uuid, Round $round)` `[player]`: `abort_unless($round->room_uuid === $uuid, 404)`; validate `lat`,`lng`; `RoundService::submitGuess($round, $request->attributes->get('player'), $lat, $lng)`. Return `{submitted, total}`.
- `RoundController@reveal(Request, $uuid, Round $round)` `[player]`: `abort_unless($round->room_uuid === $uuid, 404)`; `RoundService::revealRound`. Return the round (with results).

Route list:
```
POST   /api/rooms                              RoomController@store
POST   /api/rooms/{uuid}/join                  PlayerController@join
GET    /api/rooms/{uuid}/state                 RoomController@state        [player]
PATCH  /api/rooms/{uuid}                       RoomController@update       [player, host]
GET    /api/rooms/{uuid}/rounds/{round}/photo  RoomController@photo        [player]
POST   /api/rooms/{uuid}/rounds                RoundController@store       [player, host]
POST   /api/rooms/{uuid}/rounds/{round}/start  RoundController@start       [player, host]
POST   /api/rooms/{uuid}/rounds/{round}/guess  RoundController@guess       [player]
POST   /api/rooms/{uuid}/rounds/{round}/reveal RoundController@reveal      [player]
```
`{round}` is route-model-bound by `id`; every round method asserts `room_uuid === $uuid` (404 otherwise) to scope rounds to the room.

### 8. Broadcast events + channel contract
Channel: **public** `room-{uuid}` (public channels need no authorization; the UUIDv4 + player token are the access control).

Events (`app/Events/`), all `implements ShouldBroadcastNow` (no queue worker), all `broadcastOn(): new Channel('room-'.$room->uuid)`:
- `RoundStarted` — `broadcastAs() => 'round.started'`; `broadcastWith() => ['roundId', 'duration' (effectiveDuration), 'photoUrl' (photo URL w/ token), 'total' (player count)]`.
- `RoundGuessSubmitted` — `broadcastAs() => 'round.guess-submitted'`; `broadcastWith() => ['roundId', 'submitted' (guess count), 'total' (player count)]`. (No coordinates — anti-cheat.)
- `RoundFinished` — `broadcastAs() => 'round.finished'`; `broadcastWith() => ['roundId', 'target' => ['lat','lng'], 'results' => [ {name, lat, lng, distance, score} … sorted by score desc ], 'ranking' => [names by score desc]]`.
- `RoomUpdated` — `broadcastAs() => 'room.updated'`; `broadcastWith() => ['players' => [ {name, isHost, submitted} ], 'round' => (current round sanitized, or null)]`.

**State JSON** (from `RoomController@state`):
```json
{
  "room": { "uuid": "...", "defaultDuration": 300 },
  "players": [ { "id": 1, "name": "Alice", "isHost": true, "submitted": true } ],
  "round": {
    "id": 5, "status": "playing", "duration": 300,
    "endsAt": "2026-08-29T20:35:00Z",
    "photoUrl": "/api/rooms/{uuid}/rounds/5/photo?token=…",
    "target": null, "results": null,
    "myGuess": { "lat": 0, "lng": 0 }
  }
}
```
`target`/`results` are `null` unless `status === 'finished'`. `myGuess` = the requesting player's own guess (or null). `round` is null when the room has no round yet (lobby).

### 9. Scheduler (auto-reveal on time limit)
- Create `app/Console/Commands/RevealExpiredRooms.php`: `signature = 'vacantastic:reveal-expired'`; `handle()` → `RoundService::revealExpired()`.
- In `routes/console.php`: `Schedule::command('vacantastic:reveal-expired')->everyThirtySeconds();`
- Safety net for the per-round time limit; the client also triggers reveal at countdown 0 (snappier). Runs in its own Docker service (`php artisan schedule:work`).

### 10. Config
- `config/filesystems.php`: confirm the `local` disk `root = storage_path('app/private')` (Laravel 13 default) — photos go here (private, not web-served).
- `.env` / `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://vacantastic.example.com` (the user's real domain), `DB_CONNECTION=sqlite`, `DB_DATABASE=/var/www/app/database/database.sqlite` (Docker; local dev: `database/database.sqlite`), `CACHE_STORE=redis`, `REDIS_HOST=cache` (Docker) / `127.0.0.1` (local), `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID=vacantastic`, `REVERB_APP_KEY=<random>`, `REVERB_APP_SECRET=<random>`, `REVERB_HOST=reverb` (Docker service name — where the app pushes broadcasts), `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080`. Run `php artisan key:generate`.
- `AppServiceProvider::boot()`: if `config('database.default') === 'sqlite'`, run `DB::statement('PRAGMA journal_mode=WAL;')` and `DB::statement('PRAGMA busy_timeout=5000;')` (idempotent; safe multi-process SQLite for app+scheduler).

### 11. Frontend (Blade + game.js)
- **`resources/views/landing.blade.php`** — "Create a room": name input + button. On submit: `POST /api/rooms` → store `token`+`uuid` in `localStorage['vacantastic:'+uuid]` → `location = /r/{uuid}`.
- **`resources/views/room.blade.php`** — the single game page for `/r/{uuid}`. Loads (CDN): Leaflet 1.9.4 (JS+CSS), exifr 7 (`lite.umd.js` → `window.exifr`), pusher-js 8.0.2 (`window.Pusher`), and `/js/game.js`. Injects `window.VAC = { uuid: '<uuid>', reverbKey: '<REVERB_APP_KEY>' }`.
- **`routes/web.php`**: `GET /` → landing; `GET /r/{uuid}` → room view (404 if the room doesn't exist).

**`public/js/game.js`** — the game logic (implement all):
- **Boot**: read `uuid` from the URL path (`/r/{uuid}`) and `token` from `localStorage['vacantastic:'+uuid]`. No token → **join screen** (name → `POST /api/rooms/{uuid}/join` → store token → re-boot). Token present → `loadState()` + `connectWS()`.
- **`loadState()`**: `GET /api/rooms/{uuid}/state` (header `X-Player-Token`) → render by `round.status` (null/`setup`→lobby, `playing`→playing, `finished`→finished).
- **`connectWS()`**: `new Pusher(VAC.reverbKey, { wsHost: location.hostname, forceTLS: location.protocol==='https:', wsPort: location.port || 80, wssPort: location.port || 443, enabledTransports: ['ws','wss'] })`; `ch = pusher.subscribe('room-'+uuid)`; bind `room.updated`→`loadState()`, `round.started`→`renderPlaying()`, `round.guess-submitted`→update "X of Y", `round.finished`→`renderFinished(payload)`. (With Traefik this is `wss://<domain>/app`.)
- **Map (Leaflet + OSM)**: one map, OSM tiles.
  - *Playing*: **photo** (large, top) + **map** (below). On start, `map.setView([randLat, randLng], 4)` (random start — anti-cheat; `randLat ∈ [-58,58]`, `randLng ∈ [-180,180]`). Map click → place/move the player's marker. "Submit guess" → `POST .../guess` (pin lat/lng) → disable editing. Show "X of Y submitted".
  - *Finished*: `map.fitBounds` over target + all pins; add the **target** marker (distinct icon) + each player's pin (label: name, distance, score).
- **Host panel** (only if `isHost`):
  - *New round*: file input. On select: `exifr.gps(file)` → if `{latitude, longitude}` found, prefill the target on a small target-map; else host clicks the target-map to set it. Downscale the image (canvas, long edge ≤1600px, JPEG q0.8) before upload. Time-limit selector (1/2/3/5/10 min → seconds). "Save round" → `POST /api/rooms/{uuid}/rounds` (multipart photo + lat + lng + duration).
  - *setup*: "Start round" → `POST .../start`. *playing*: "Reveal now" → `POST .../reveal`. *finished*: "Next round" (re-show the uploader).
- **Timer**: while `playing`, a `mm:ss` countdown from `round.endsAt`; at 0 → `POST .../reveal` (scheduler is the backup).
- **Results panel** (finished): table sorted by score (name, distance in km, score), winner highlighted.

### 12. Docker + Traefik
**`Dockerfile`** (shared base image for app/reverb/scheduler):
```
FROM php:8.5-alpine
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /var/www/app
COPY . .
RUN composer install --no-dev --optimize-autoloader
```
(`pdo_sqlite`/`sqlite3` are in the base alpine image; Reverb needs no Swoole; no Node — the frontend is CDN + static `game.js`.) Verify the exact `php:8.5-alpine3.*` tag at build; use the newest 8.5 alpine.

**`docker-compose.yml`** (4 services; all PHP services share the code bind mount + the SQLite file in `database/`; **no host ports** — Traefik routes everything). Use a compose variable `APP_DOMAIN` (e.g. `vacantastic.example.com`) and `TRAEFIK_NETWORK` (the name of the user's Traefik Docker network).

- **`app`**: `build: .`; `command: php artisan serve --host=0.0.0.0 --port=80`; env `APP_URL=https://${APP_DOMAIN}`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/var/www/app/database/database.sqlite`, `CACHE_STORE=redis`, `REDIS_HOST=cache`, `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `PHP_CLI_SERVER_WORKERS=4`; `volumes: [./:/var/www/app]`; `networks: [${TRAEFIK_NETWORK}]`; `depends_on: [cache]`; labels:
  ```
  traefik.enable=true
  traefik.http.routers.app.rule=Host(`${APP_DOMAIN}`) && !PathPrefix(`/app`)
  traefik.http.routers.app.entrypoints=websecure
  traefik.http.routers.app.tls=true
  traefik.http.services.app.loadbalancer.server.port=80
  ```
- **`reverb`**: `build: .`; `command: php artisan reverb:start --host=0.0.0.0 --port=8080`; env `REVERB_APP_ID/KEY/SECRET`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080`, `CACHE_STORE=redis`, `REDIS_HOST=cache`, `BROADCAST_CONNECTION=reverb`; `volumes: [./:/var/www/app]`; `networks: [${TRAEFIK_NETWORK}]`; `depends_on: [cache]`; labels:
  ```
  traefik.enable=true
  traefik.http.routers.reverb.rule=Host(`${APP_DOMAIN}`) && PathPrefix(`/app`)
  traefik.http.routers.reverb.entrypoints=websecure
  traefik.http.routers.reverb.tls=true
  traefik.http.services.reverb.loadbalancer.server.port=8080
  ```
  (Traefik auto-upgrades the `/app` connection to WebSocket; Reverb listens for WS at `/app`.)
- **`scheduler`**: `build: .`; `command: php artisan schedule:work`; env `DB_CONNECTION=sqlite`, `DB_DATABASE=…`, `CACHE_STORE=redis`, `REDIS_HOST=cache`; `volumes: [./:/var/www/app]`; `networks: [${TRAEFIK_NETWORK}]`; `depends_on: [cache]`. (No Traefik labels — internal only.)
- **`cache`**: `image: eqalpha/keydb`; `command: ["--databases","32"]`; `environment: REDIS_REPLICATION_MODE=master, ALLOW_EMPTY_PASSWORD=yes`; `networks: [${TRAEFIK_NETWORK}]`.

Top-level `networks:` block declares `${TRAEFIK_NETWORK}` (set it to the user's Traefik network name so the containers share Traefik's network; if Traefik lives in this same compose, define it as an internal network here instead).

Run: `docker compose up -d --build`, then `docker compose exec app php artisan migrate` and `php artisan key:generate` (if `APP_KEY` empty). Ensure Traefik has a TLS certificate for `${APP_DOMAIN}` (e.g. a Let's Encrypt `certresolver`) so `tls=true` resolves.

## Critical files & anchors
- `app/Services/RoundService.php` — the state machine; `revealRound` idempotency + "all submitted → reveal" + `revealExpired` are the core game logic.
- `app/Http/Middleware/EnsurePlayerToken.php` — the only auth; token from header-or-query, attached via `$request->attributes`; plus the `host` check.
- `routes/api.php` + `bootstrap/app.php` — the route list, the `player`/`host` aliases, and the `room_uuid === $uuid` scoping in every round method.
- `public/js/game.js` — the whole client (map, EXIF, WS, timer, host panel); the anti-cheat random map start + no-pins-until-reveal live here.
- `docker-compose.yml` — the 4-service topology, the Traefik labels (app excludes `/app`, reverb owns `/app`), and the `APP_DOMAIN`/`TRAEFIK_NETWORK` variables.

## Verification
Prereq: a Traefik instance on the same Docker network with a TLS cert for the domain; `docker compose up -d --build`; `docker compose exec app php artisan migrate`. Open `https://<domain>`.
1. **Create + share**: enter a name → room created; UI shows the shareable link `https://<domain>/r/{uuid}` + copy button. Confirm the UUID is v4 (8-4-4-4-12 hex).
2. **Join (2nd browser/incognito)**: open the link in a private window → different name → both clients show each other in the lobby (via `room.updated` over `wss`).
3. **EXIF**: host uploads a photo **with** GPS EXIF → target auto-fills on the target map (prefill matches the photo's real place). Upload a photo **without** GPS → host sets the target by clicking the map.
4. **Play**: host sets a 1-min limit, starts. Both players see the photo + a map at a random location. Each places a pin and submits. The "X of 2 submitted" counter updates live on both clients (WS).
5. **Reveal + win**: on submit-all / timer / "Reveal now", both clients reveal the true location + all pins + a score table; closest player ranked #1. Confirm the math (a pin ~1609 m away scores ≈1838; a pin on the target scores 5000).
6. **Timer auto-reveal**: start a 30 s round, submit nothing, wait ~30 s + a scheduler tick → the round reveals automatically and unsubmitted players score 0.
7. **Privacy**: with no/invalid token, `GET /api/rooms/{uuid}/rounds/{id}/photo` → 401; the photo is unreachable without a member token. Confirm `target` is absent from the `state` JSON while `status !== 'finished'`.
8. **WS via Traefik**: confirm the browser Network/WS panel shows a single `wss://<domain>/app` socket and that live updates (join, submit, reveal) arrive without a page refresh.

## Assumptions & contingencies
- **Traefik is the edge** (per user): the page is `https://<domain>` and the WS is `wss://<domain>/app` (no mixed-content block, no exposed Reverb port). The client derives the socket host/protocol from `location`, so no client change is needed if the domain or TLS setup differs. If the user's Traefik uses a non-default TLS entrypoint, set the `entrypoints` label to match (commonly `websecure`).
- **`php:8.5-alpine` tag**: if that exact tag isn't published yet at build time, use the newest `php:8.5-alpine3.*` (Laravel 13 supports PHP 8.3–8.5).
- **`artisan serve`** is dev-grade (worker pool via `PHP_CLI_SERVER_WORKERS=4`); fine for a party game. Upgrade path (not now): swap the `app` command for `php artisan octane:start` (Swoole) — the rest of the stack is unchanged.
- **Public channels** on purpose (room UUIDv4 + player token are the access control); no Laravel auth/Sanctum. If per-connection auth is wanted later, switch to `PrivateChannel` + a `routes/channels.php` callback that validates the token.
- **SQLite concurrency**: app + scheduler both write; WAL + `busy_timeout=5000` covers party-game scale. A "database is locked" error is the signal to move to a MariaDB service (the hekate pattern).
- **exifr `lite` build** reads JPEG + HEIC (the common vacation-photo formats); a photo with no readable GPS falls back to manual target entry, so no format is a hard blocker.
- **Network name**: `TRAEFIK_NETWORK` must match the user's Traefik Docker network (`docker network ls`); if Traefik is in this same compose, declare it as an internal network instead of referencing an external one.
