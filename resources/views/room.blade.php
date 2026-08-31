<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vacantastic</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root {
            --bg: #0b1020;
            --panel: #151d33;
            --panel-2: #1e2946;
            --text: #e8ecf6;
            --muted: #93a0c0;
            --accent: #ff8a3d;
            --accent-2: #2dd4bf;
            --danger: #f87171;
            --line: #26325a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            background: radial-gradient(1200px 600px at 50% -10%, #1b2b52 0%, var(--bg) 60%);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 22px; border-bottom: 1px solid var(--line);
            position: sticky; top: 0; background: rgba(11,16,32,.85); backdrop-filter: blur(8px); z-index: 1000;
        }
        .logo { font-size: 20px; font-weight: 800; letter-spacing: -.5px; }
        .logo span { color: var(--accent); }
        #share-bar { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
        #share-bar .link { color: var(--accent-2); max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        button {
            padding: 9px 14px; font-size: 13px; font-weight: 700; cursor: pointer;
            color: #10131c; background: var(--accent); border: none; border-radius: 8px;
        }
        button:hover { filter: brightness(1.06); }
        button:disabled { opacity: .5; cursor: default; filter: none; }
        button.secondary { background: var(--panel-2); color: var(--text); border: 1px solid var(--line); }
        button.ghost { background: transparent; color: var(--accent-2); border: 1px solid var(--accent-2); }
        main { max-width: 1100px; margin: 0 auto; padding: 22px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
        h2 { margin: 0 0 6px; font-size: 20px; }
        .muted { color: var(--muted); font-size: 13px; }
        input[type=text], input[type=number], select {
            padding: 11px 12px; font-size: 14px; background: var(--panel-2); color: var(--text);
            border: 1px solid #2c3a66; border-radius: 8px; outline: none;
        }
        input[type=text]:focus, select:focus { border-color: var(--accent-2); }
        .center { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; gap: 14px; }
        .center input[type=text] { width: 260px; }

        /* Players list */
        .players { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .chip {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--panel-2); border: 1px solid var(--line);
            padding: 6px 12px; border-radius: 999px; font-size: 13px;
        }
        .chip .dot { width: 8px; height: 8px; border-radius: 50%; background: #556; }
        .chip.submitted .dot { background: var(--accent-2); }
        .chip .host { color: var(--accent); font-weight: 700; }
        .chip .you { color: var(--accent-2); }
        .chip .score { color: var(--muted); font-variant-numeric: tabular-nums; }

        /* Layout for playing / finished (photo + map side by side) */
        .grid { display: grid; gap: 16px; }
        .grid.playing, .grid.finished { grid-template-columns: 1fr; }
        @media (min-width: 900px) { .grid.playing, .grid.finished { grid-template-columns: 420px 1fr; align-items: start; } }
        .photo-wrap { border-radius: 12px; overflow: hidden; border: 1px solid var(--line); background: #000; }
        .photo-wrap img { width: 100%; display: block; max-height: 460px; object-fit: contain; background: #000; cursor: zoom-in; }
        .map { height: clamp(420px, 70vh, 720px); border-radius: 12px; border: 1px solid var(--line); background: #0a0f1e; }
        .map.small { height: 260px; }
        .leaflet-container { background: #0a0f1e; }

        .toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
        .timer { font-variant-numeric: tabular-nums; font-size: 22px; font-weight: 800; color: var(--accent-2); }
        .timer.warn { color: var(--danger); }
        .tally { color: var(--muted); font-size: 14px; }

        /* Results */
        table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        tr.winner td { background: rgba(255,138,61,.12); }
        tr.winner td:first-child { color: var(--accent); font-weight: 800; }
        .you-row td { color: var(--accent-2); }

        /* Host panel */
        .host-panel { margin-top: 16px; }
        .host-panel h3 { margin: 0 0 12px; font-size: 15px; color: var(--accent); }
        .file-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .file-row input[type=file] { color: var(--muted); font-size: 13px; }
        .exif-note { font-size: 12px; margin-top: 8px; }
        .exif-note.ok { color: var(--accent-2); }
        .exif-note.warn { color: var(--accent); }
        .target-row { margin-top: 12px; }
        .target-row .coords { font-size: 13px; color: var(--muted); margin-top: 8px; font-variant-numeric: tabular-nums; }
        .privacy-note { font-size: 12px; color: var(--muted); margin: 10px 0 0; line-height: 1.5; }

        /* Photo lightbox (zoom) */
        .lightbox {
            position: fixed; inset: 0; z-index: 3000;
            background: rgba(5, 8, 18, .93);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .lightbox img {
            max-width: 100%; max-height: 100%; object-fit: contain;
            transform-origin: center center;
            touch-action: none; cursor: grab;
        }
        .lightbox img:active { cursor: grabbing; }
        .lb-bar { position: absolute; top: 14px; right: 14px; display: flex; gap: 8px; }
        .lb-btn {
            width: 40px; height: 40px; padding: 0; font-size: 18px; line-height: 1;
            background: var(--panel-2); color: var(--text);
            border: 1px solid var(--line); border-radius: 8px;
        }
        .lb-btn:hover { filter: brightness(1.2); }

        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: var(--panel-2); border: 1px solid var(--line); color: var(--text);
            padding: 10px 18px; border-radius: 10px; font-size: 13px; opacity: 0; transition: opacity .2s; z-index: 2000;
        }
        .toast.show { opacity: 1; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <header>
        <div class="logo">Vacan<span>tastic</span></div>
        <div id="share-bar">
            <span class="link" id="share-link"></span>
            <button class="secondary" id="copy-link">Copy link</button>
        </div>
    </header>
    <main id="game">
        <div class="center muted" id="boot">Loading…</div>
    </main>

    <div class="toast" id="toast"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/exifr@7.1.3/dist/lite.umd.js"></script>
    <script src="https://js.pusher.com/8.0.2/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
    <script>
        window.VAC = {
            uuid: @json($uuid),
            reverbKey: @json(config('broadcasting.connections.reverb.key')),
            reverbPort: @json(env('REVERB_CLIENT_PORT', '')),
        };
    </script>
    <script src="/js/game.js"></script>
</body>
</html>
