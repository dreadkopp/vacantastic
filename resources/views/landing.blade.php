<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vacantastic</title>
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
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: radial-gradient(1200px 600px at 50% -10%, #1b2b52 0%, var(--bg) 60%);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 24px;
        }
        .card {
            width: 100%; max-width: 440px;
            background: var(--panel);
            border: 1px solid #26325a;
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 30px 80px rgba(0,0,0,.45);
        }
        .logo { font-size: 30px; font-weight: 800; letter-spacing: -.5px; margin: 0 0 6px; }
        .logo span { color: var(--accent); }
        .tagline { color: var(--muted); margin: 0 0 26px; line-height: 1.5; }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 8px; }
        input[type=text] {
            width: 100%; padding: 13px 14px; font-size: 15px;
            background: var(--panel-2); color: var(--text);
            border: 1px solid #2c3a66; border-radius: 10px; outline: none;
        }
        input[type=text]:focus { border-color: var(--accent-2); }
        button {
            width: 100%; margin-top: 16px; padding: 14px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            color: #1a1206; background: var(--accent);
            border: none; border-radius: 10px;
        }
        button:hover { filter: brightness(1.06); }
        button:disabled { opacity: .6; cursor: default; }
        .error { color: var(--danger); min-height: 18px; margin: 14px 0 0; font-size: 13px; }
        .steps { margin-top: 28px; padding-top: 22px; border-top: 1px solid #26325a; color: var(--muted); font-size: 13px; line-height: 1.7; }
        .steps b { color: var(--text); }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="logo">Vacan<span>tastic</span></h1>
        <p class="tagline">Upload a vacation photo. Your friends guess where it was taken. Closest to the truth wins.</p>

        <form id="create-form">
            <label for="name">Your name</label>
            <input type="text" id="name" maxlength="32" placeholder="e.g. Alex" autocomplete="off" required>
            <button type="submit" id="create-btn">Create a room</button>
            <p class="error" id="error"></p>
        </form>

        <div class="steps">
            <b>How it works</b><br>
            1. Create a room and share the link.<br>
            2. You upload a photo — its GPS is read automatically.<br>
            3. Everyone drops a pin on the map.<br>
            4. Closest guess takes the round.
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('create-form');
            const input = document.getElementById('name');
            const btn = document.getElementById('create-btn');
            const errEl = document.getElementById('error');

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const name = input.value.trim();
                if (!name) { errEl.textContent = 'Please enter a name.'; return; }
                btn.disabled = true;
                btn.textContent = 'Creating…';
                errEl.textContent = '';
                try {
                    const res = await fetch('/api/rooms', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name }),
                    });
                    if (!res.ok) {
                        const body = await res.json().catch(() => ({}));
                        throw new Error(body.message || 'Could not create the room.');
                    }
                    const data = await res.json();
                    localStorage.setItem('vacantastic:' + data.uuid, data.token);
                    location.href = '/r/' + data.uuid;
                } catch (err) {
                    errEl.textContent = err.message || 'Something went wrong.';
                    btn.disabled = false;
                    btn.textContent = 'Create a room';
                }
            });
        })();
    </script>
</body>
</html>
