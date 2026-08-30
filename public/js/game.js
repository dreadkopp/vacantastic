/* Vacantastic — game client.
 *
 * Reads the room uuid from window.VAC (injected by the Blade template) and the
 * player token from localStorage. Renders the game based on the room state and
 * stays in sync over a Reverb (Pusher-protocol) WebSocket.
 */
(function () {
  'use strict';

  const VAC = window.VAC || {};
  const uuid = VAC.uuid;
  const game = document.getElementById('game');
  const toastEl = document.getElementById('toast');

  const state = {
    uuid,
    token: localStorage.getItem('vacantastic:' + uuid),
    room: null,
    players: [],
    round: null,
  };

  // ---- Map / timer handles (destroyed on each full re-render) ----
  let gameMap = null;
  let targetMap = null;
  let myMarker = null;
  let targetMarker = null;
  let myPin = null;
  let target = null;
  let timerInterval = null;
  let pusher = null;

  const OSM = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  const OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

  // ---------- helpers ----------
  function me() { return state.players.find((p) => p.you) || null; }
  function isHost() { return !!(me() && me().isHost); }

  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toastEl.classList.remove('show'), 2600);
  }

  async function api(method, path, body) {
    const headers = {};
    if (state.token) headers['X-Player-Token'] = state.token;
    let payload;
    if (body instanceof FormData) payload = body;
    else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    const res = await fetch(path, { method, headers, body: payload });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || `Request failed (${res.status})`);
    }
    return res.status === 204 ? null : res.json();
  }

  // The photo URL needs the player's own token. The state endpoint already embeds
  // it; the (shared) broadcast does not, so append it when missing.
  function photoSrc(url) {
    if (!url) return null;
    if (url.includes('token=')) return url;
    return url + (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(state.token);
  }

  function fmtDist(m) {
    if (m == null) return '—';
    if (m < 1000) return Math.round(m) + ' m';
    return (m / 1000).toFixed(m < 10000 ? 2 : 1) + ' km';
  }

  function cleanup() {
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    if (gameMap) { gameMap.remove(); gameMap = null; }
    if (targetMap) { targetMap.remove(); targetMap = null; }
    myMarker = null; targetMarker = null; myPin = null; target = null;
  }

  // ---------- state ----------
  async function loadState() {
    try {
      const data = await api('GET', `/api/rooms/${state.uuid}/state`);
      state.room = data.room;
      state.players = data.players;
      state.round = data.round;
      render();
    } catch (e) {
      if (e.message.includes('401') || /401/.test(e.message)) {
        // Token invalid — force re-join.
        state.token = null;
        localStorage.removeItem('vacantastic:' + state.uuid);
        renderJoin();
      } else {
        toast(e.message);
      }
    }
  }

  function render() {
    cleanup();
    const status = state.round ? state.round.status : null;
    if (!state.round || status === 'setup') renderLobby();
    else if (status === 'playing') renderPlaying();
    else if (status === 'finished') renderFinished();
  }

  // ---------- join ----------
  function renderJoin() {
    game.innerHTML = `
      <div class="center">
        <h2>Join the game</h2>
        <p class="muted">Enter a name to join this room.</p>
        <input type="text" id="join-name" maxlength="32" placeholder="Your name" autocomplete="off">
        <button id="join-btn">Join</button>
        <p class="muted" id="join-err" style="color:var(--danger)"></p>
      </div>`;
    const input = document.getElementById('join-name');
    const btn = document.getElementById('join-btn');
    const errEl = document.getElementById('join-err');
    const doJoin = async () => {
      const name = input.value.trim();
      if (!name) { errEl.textContent = 'Please enter a name.'; return; }
      btn.disabled = true;
      try {
        const res = await api('POST', `/api/rooms/${state.uuid}/join`, { name });
        state.token = res.token;
        localStorage.setItem('vacantastic:' + state.uuid, state.token);
        connectWS();
        await loadState();
      } catch (e) {
        errEl.textContent = e.message;
        btn.disabled = false;
      }
    };
    btn.addEventListener('click', doJoin);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') doJoin(); });
    input.focus();
  }

  // ---------- players ----------
  function renderPlayers() {
    const el = document.getElementById('players');
    if (!el) return;
    el.innerHTML = state.players.map((p) => `
      <span class="chip ${p.submitted ? 'submitted' : ''}">
        <span class="dot"></span>
        <span class="${p.isHost ? 'host' : ''}">${escapeHtml(p.name)}</span>
        ${p.isHost ? '<span class="host">host</span>' : ''}
        ${p.you ? '<span class="you">you</span>' : ''}
      </span>`).join('');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // ---------- lobby ----------
  function renderLobby() {
    const hasRound = !!state.round;
    game.innerHTML = `
      <div class="card">
        <h2>${hasRound ? 'Round is ready' : 'Waiting for the host'}</h2>
        <p class="muted">${hasRound ? 'A round is set up and waiting to start.' : 'The host will upload a vacation photo to begin.'}</p>
        <div class="players" id="players"></div>
        <div id="host-controls">${hostControls()}</div>
      </div>`;
    renderPlayers();
    wireHostControls();
  }

  // ---------- playing ----------
  function renderPlaying() {
    const round = state.round;
    const host = isHost();
    game.innerHTML = `
      <div class="grid playing">
        <div>
          <div class="photo-wrap"><img id="photo" alt="Where was this photo taken?"></div>
          <div class="toolbar">
            <div class="timer" id="timer">--:--</div>
            <div class="tally" id="tally"></div>
          </div>
          <div class="toolbar">
            ${host
              ? '<span class="muted">You set the location — you\'re watching this round.</span>'
              : '<button id="submit-btn" disabled>Submit guess</button><span class="muted">Click the map to drop your pin.</span>'}
            ${host ? '<button id="reveal-btn" class="secondary">Reveal now</button>' : ''}
          </div>
        </div>
        <div class="map" id="map"></div>
      </div>`;

    const url = photoSrc(round.photoUrl);
    if (url) {
      const img = document.getElementById('photo');
      img.src = url;
      img.onerror = () => { img.alt = 'Photo unavailable'; };
    }

    initGameMap(host);
    startTimer(round.endsAt);
    updateTally();

    if (!host) document.getElementById('submit-btn').addEventListener('click', submitGuess);
    if (host) document.getElementById('reveal-btn').addEventListener('click', reveal);
  }

  function initGameMap(host) {
    if (gameMap) { gameMap.remove(); gameMap = null; }
    myMarker = null;
    myPin = null;
    gameMap = L.map('map');
    L.tileLayer(OSM, { maxZoom: 19, attribution: OSM_ATTR }).addTo(gameMap);

    // Everyone starts at Null Island (0, 0) — the classic GeoGuessr start.
    gameMap.setView([0, 0], 4);

    // The host watches; only guessers place a pin.
    if (host) return;
    const already = state.round && state.round.myGuess;
    if (already) {
      myPin = { lat: already.lat, lng: already.lng };
      myMarker = L.marker([already.lat, already.lng]).addTo(gameMap);
      const btn = document.getElementById('submit-btn');
      if (btn) { btn.disabled = true; btn.textContent = 'Submitted ✓'; }
    } else {
      gameMap.on('click', (e) => setMyPin(e.latlng.lat, e.latlng.lng));
    }
  }

  function setMyPin(lat, lng) {
    myPin = { lat, lng };
    if (myMarker) myMarker.setLatLng([lat, lng]);
    else {
      myMarker = L.marker([lat, lng], { draggable: true }).addTo(gameMap);
      myMarker.on('drag', () => { const p = myMarker.getLatLng(); myPin = { lat: p.lat, lng: p.lng }; });
    }
    const btn = document.getElementById('submit-btn');
    if (btn) btn.disabled = false;
  }

  async function submitGuess() {
    if (!myPin) return;
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Submitting…';
    try {
      await api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/guess`, { lat: myPin.lat, lng: myPin.lng });
      // Lock the map; the reveal will arrive via the round.finished broadcast.
      if (myMarker) myMarker.dragging.disable();
      gameMap.off('click');
      btn.textContent = 'Submitted ✓';
    } catch (e) {
      toast(e.message);
      btn.disabled = false;
      btn.textContent = 'Submit guess';
    }
  }

  function updateTally() {
    const el = document.getElementById('tally');
    if (!el || !state.round) return;
    const guessers = state.players.filter((p) => !p.isHost);
    const submitted = guessers.filter((p) => p.submitted).length;
    el.textContent = `${submitted} of ${guessers.length} guessing`;
  }

  // ---------- finished ----------
  function renderFinished() {
    const round = state.round;
    game.innerHTML = `
      <div class="card">
        <h2>Results</h2>
        <p class="muted">The true location is revealed. Closest guess wins.</p>
        <div class="map" id="map" style="margin-bottom:16px"></div>
        <table>
          <thead><tr><th>#</th><th>Player</th><th>Distance</th><th>Score</th></tr></thead>
          <tbody id="results-body"></tbody>
        </table>
        <div id="host-controls" style="margin-top:16px">${hostControls()}</div>
      </div>`;
    renderResultsMap();
    renderResultsTable();
    wireHostControls();
  }

  function targetIcon() {
    return L.divIcon({
      className: '',
      html: '<div style="width:22px;height:22px;border-radius:50%;background:#ff8a3d;border:3px solid #fff;box-shadow:0 0 0 3px rgba(255,138,61,.5)"></div>',
      iconSize: [22, 22],
      iconAnchor: [11, 11],
    });
  }

  function renderResultsMap() {
    const round = state.round;
    if (gameMap) { gameMap.remove(); gameMap = null; }
    gameMap = L.map('map');
    L.tileLayer(OSM, { maxZoom: 19, attribution: OSM_ATTR }).addTo(gameMap);

    const pts = [];
    if (round.target) {
      L.marker([round.target.lat, round.target.lng], { icon: targetIcon(), zIndexOffset: 1000 })
        .addTo(gameMap).bindTooltip('True location', { permanent: false });
      pts.push([round.target.lat, round.target.lng]);
    }
    for (const r of round.results) {
      if (r.lat != null && r.lng != null) {
        L.marker([r.lat, r.lng]).addTo(gameMap)
          .bindTooltip(`${escapeHtml(r.name)} — ${fmtDist(r.distance)}`);
        pts.push([r.lat, r.lng]);
      }
    }
    if (pts.length > 1) gameMap.fitBounds(L.latLngBounds(pts).pad(0.35));
    else if (pts.length === 1) gameMap.setView(pts[0], 6);
  }

  function renderResultsTable() {
    const body = document.getElementById('results-body');
    if (!body) return;
    const myId = me() ? me().id : null;
    body.innerHTML = state.round.results.map((r, i) => {
      const winner = i === 0 && r.score > 0;
      const you = r.id === myId;
      return `<tr class="${winner ? 'winner' : ''} ${you ? 'you-row' : ''}">
        <td>${i + 1}</td>
        <td>${escapeHtml(r.name)}${you ? ' <span class="you" style="color:var(--accent-2)">(you)</span>' : ''}</td>
        <td>${fmtDist(r.distance)}</td>
        <td>${r.score}</td>
      </tr>`;
    }).join('');
  }

  // ---------- host controls ----------
  function hostControls() {
    if (!isHost()) return '';
    const status = state.round ? state.round.status : null;
    if (!state.round || status === 'finished') {
      return `
        <div class="host-panel">
          <h3>${state.round ? 'Next round' : 'New round'}</h3>
          <div class="file-row">
            <input type="file" id="photo" accept="image/*">
            <select id="duration" title="Time limit">
              <option value="60">1 min</option>
              <option value="120">2 min</option>
              <option value="180">3 min</option>
              <option value="300" selected>5 min</option>
              <option value="600">10 min</option>
              <option value="0">unlimited</option>
            </select>
          </div>
          <p class="exif-note" id="exif-note"></p>
          <div class="target-row">
            <div class="map small" id="target-map"></div>
            <div class="coords" id="target-coords">Set the target: upload a photo with GPS, or click the map.</div>
          </div>
          <div class="toolbar"><button id="save-round">Save round</button></div>
        </div>`;
    }
    if (status === 'setup') {
      return `<div class="host-panel"><h3>Ready to start</h3><button id="start-btn">Start round</button></div>`;
    }
    return '';
  }

  function wireHostControls() {
    if (!isHost()) return;
    const status = state.round ? state.round.status : null;

    if (!state.round || status === 'finished') {
      const fileInput = document.getElementById('photo');
      const note = document.getElementById('exif-note');
      if (fileInput) fileInput.addEventListener('change', () => onPhotoSelect(fileInput.files[0], note));
      if (document.getElementById('save-round')) document.getElementById('save-round').addEventListener('click', saveRound);
      initTargetMap();
    } else if (status === 'setup') {
      const btn = document.getElementById('start-btn');
      if (btn) btn.addEventListener('click', startRound);
    }
  }

  function initTargetMap() {
    if (targetMap) { targetMap.remove(); targetMap = null; }
    targetMarker = null;
    target = null;
    targetMap = L.map('target-map');
    L.tileLayer(OSM, { maxZoom: 19, attribution: OSM_ATTR }).addTo(targetMap);
    targetMap.setView([20, 0], 2);
    targetMap.on('click', (e) => {
      target = { lat: e.latlng.lat, lng: e.latlng.lng };
      updateTargetMarker();
    });
  }

  function updateTargetMarker() {
    if (!target) return;
    if (targetMarker) targetMarker.setLatLng([target.lat, target.lng]);
    else targetMarker = L.marker([target.lat, target.lng]).addTo(targetMap);
    const el = document.getElementById('target-coords');
    if (el) el.textContent = `Target: ${target.lat.toFixed(5)}, ${target.lng.toFixed(5)}`;
  }

  async function onPhotoSelect(file, note) {
    if (!file) return;
    note.className = 'exif-note warn';
    note.textContent = 'Reading photo…';
    const gps = await readGps(file);
    if (gps) {
      target = gps;
      updateTargetMarker();
      if (targetMap) targetMap.setView([gps.lat, gps.lng], 12);
      note.className = 'exif-note ok';
      note.textContent = `GPS found: ${gps.lat.toFixed(5)}, ${gps.lng.toFixed(5)}`;
    } else {
      note.className = 'exif-note warn';
      note.textContent = 'No GPS in photo — click the map to set the target.';
    }
  }

  async function readGps(file) {
    try {
      if (window.exifr && typeof exifr.gps === 'function') {
        const gps = await exifr.gps(file);
        if (gps && gps.latitude != null && gps.longitude != null) {
          return { lat: gps.latitude, lng: gps.longitude };
        }
      }
    } catch (e) { /* fall through to manual entry */ }
    return null;
  }

  // Convert HEIC/HEIF to JPEG (most browsers can't decode HEIC natively, and the
  // server rejects it), then downscale. Returns a JPEG blob ready to upload.
  async function preparePhoto(file) {
    let src = file;
    const isHeic = /image\/he[i][cf]/.test(file.type) || /\.(heic|heif)$/i.test(file.name || '');
    if (isHeic && typeof window.heic2any === 'function') {
      try {
        src = await window.heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 });
      } catch (e) {
        throw new Error('Could not read this HEIC photo. Try a JPEG or PNG instead.');
      }
    }
    return downscale(src);
  }

  function downscale(file, maxEdge = 1600, quality = 0.8) {
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        const scale = Math.min(1, maxEdge / Math.max(img.width, img.height));
        const w = Math.round(img.width * scale);
        const h = Math.round(img.height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        canvas.toBlob((b) => resolve(b || file), 'image/jpeg', quality);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  async function saveRound() {
    const file = document.getElementById('photo').files[0];
    if (!file) { toast('Choose a photo first.'); return; }
    if (!target) { toast('Set the target location.'); return; }
    const btn = document.getElementById('save-round');
    btn.disabled = true;
    btn.textContent = 'Preparing photo…';
    try {
      const blob = await preparePhoto(file);
      btn.textContent = 'Saving…';
      const fd = new FormData();
      fd.append('photo', blob, (file.name.replace(/\.\w+$/, '') || 'photo') + '.jpg');
      fd.append('lat', target.lat);
      fd.append('lng', target.lng);
      fd.append('duration', document.getElementById('duration').value);
      await api('POST', `/api/rooms/${state.uuid}/rounds`, fd);
      toast('Round saved.');
      // The room.updated broadcast will re-render; also refresh for snappiness.
      loadState();
    } catch (e) {
      toast(e.message);
      btn.disabled = false;
      btn.textContent = 'Save round';
    }
  }

  async function startRound() {
    try {
      await api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/start`);
      loadState();
    } catch (e) { toast(e.message); }
  }

  async function reveal() {
    try {
      await api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/reveal`);
      loadState();
    } catch (e) { toast(e.message); }
  }

  // ---------- timer ----------
  function startTimer(endsAt) {
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    const el = document.getElementById('timer');
    if (!el) return;
    if (!endsAt) {
      el.textContent = '∞';
      el.classList.remove('warn');
      return;
    }
    const end = new Date(endsAt).getTime();
    const tick = () => {
      const remain = Math.max(0, Math.round((end - Date.now()) / 1000));
      const m = Math.floor(remain / 60);
      const s = remain % 60;
      el.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
      el.classList.toggle('warn', remain <= 10);
      if (remain <= 0) {
        clearInterval(timerInterval);
        timerInterval = null;
        // Trigger reveal for snappiness; the scheduler is the backup.
        api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/reveal`).catch(() => {});
      }
    };
    tick();
    timerInterval = setInterval(tick, 1000);
  }

  // ---------- websocket ----------
  function connectWS() {
    if (pusher || typeof Pusher === 'undefined') return;
    const port = VAC.reverbPort
      ? parseInt(VAC.reverbPort, 10)
      : (location.port ? parseInt(location.port, 10) : (location.protocol === 'https:' ? 443 : 80));
    const host = VAC.reverbHost || location.hostname;

    pusher = new Pusher(VAC.reverbKey, {
      wsHost: host,
      forceTLS: location.protocol === 'https:',
      wsPort: port,
      wssPort: port,
      enabledTransports: ['ws', 'wss'],
      cluster: 'mt1',
    });

    const ch = pusher.subscribe('room-' + state.uuid);
    ch.bind('room.updated', () => loadState());
    ch.bind('round.started', () => loadState());
    ch.bind('round.finished', () => loadState());
    ch.bind('round.guess-submitted', (data) => {
      if (state.round && data.roundId === state.round.id) {
        const el = document.getElementById('tally');
        if (el) el.textContent = `${data.submitted} of ${data.total} submitted`;
      }
    });
    ch.bind('subscription_succeeded', () => { /* connected */ });
  }

  // ---------- share bar ----------
  function wireShareBar() {
    const link = location.origin + '/r/' + state.uuid;
    const linkEl = document.getElementById('share-link');
    if (linkEl) linkEl.textContent = link;
    const btn = document.getElementById('copy-link');
    if (btn) btn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(link); toast('Link copied!'); }
      catch (e) { toast(link); }
    });
  }

  // ---------- boot ----------
  function boot() {
    wireShareBar();
    if (!state.token) {
      renderJoin();
    } else {
      connectWS();
      loadState();
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
