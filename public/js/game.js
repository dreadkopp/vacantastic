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
  let hostChannel = null;
  const hostGuessMarkers = new Map();
  const hostViewCircles = new Map();

  const OSM = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  const OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

  // Localized OSM tile servers (labels in the viewer's own language), keyed by
  // language code. Only servers verified to be up are listed; anything else
  // falls back to the standard OSM layer (English labels).
  const LOCALIZED_TILES = {
    de: { url: 'https://tile.openstreetmap.de/{z}/{x}/{y}.png', attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://openstreetmap.de/">OSM.DE</a>' },
    fr: { url: 'https://a.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://www.openstreetmap.fr/">OSM France</a>' },
    ja: { url: 'https://tile.openstreetmap.jp/{z}/{x}/{y}.png', attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://www.openstreetmap.jp/">OSM Japan</a>' },
  };

  // Tile layer for the viewer's language (e.g. German street names for a
  // German browser). Used by every map in the game.
  function mapTiles() {
    const lang = (((navigator.languages && navigator.languages[0]) || navigator.language || 'en').slice(0, 2)).toLowerCase();
    const def = LOCALIZED_TILES[lang] || { url: OSM, attribution: OSM_ATTR };
    return L.tileLayer(def.url, { maxZoom: 19, attribution: def.attribution });
  }

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

  // ---------- photo lightbox (zoom) ----------
  // Click the photo to inspect it full-size: wheel/pinch to zoom, drag to pan.
  function openLightbox(url) {
    if (!url || document.querySelector('.lightbox')) return;

    const lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.innerHTML = `
      <div class="lb-bar">
        <button class="lb-btn" data-act="out" title="Zoom out">&minus;</button>
        <button class="lb-btn" data-act="in" title="Zoom in">+</button>
        <button class="lb-btn" data-act="reset" title="Fit to screen">1:1</button>
        <button class="lb-btn" data-act="close" title="Close">&times;</button>
      </div>
      <img src="${url}" alt="Photo">`;
    document.body.appendChild(lb);

    const img = lb.querySelector('img');
    let scale = 1, tx = 0, ty = 0;
    const apply = () => { img.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`; };

    // Zoom while keeping the point (cx, cy) — offset from the overlay center — fixed.
    const zoomAt = (cx, cy, factor) => {
      const next = Math.min(8, Math.max(1, scale * factor));
      const f = next / scale;
      tx = cx - (cx - tx) * f;
      ty = cy - (cy - ty) * f;
      scale = next;
      if (scale === 1) { tx = 0; ty = 0; }
      apply();
    };

    const centerOffset = (clientX, clientY) => {
      const r = lb.getBoundingClientRect();
      return { x: clientX - (r.left + r.width / 2), y: clientY - (r.top + r.height / 2) };
    };

    lb.addEventListener('wheel', (e) => {
      e.preventDefault();
      const c = centerOffset(e.clientX, e.clientY);
      zoomAt(c.x, c.y, e.deltaY < 0 ? 1.2 : 1 / 1.2);
    }, { passive: false });

    // Drag to pan; two-finger pinch to zoom (touch).
    const pointers = new Map();
    let pinchDist = null;
    let downOnBackground = false;
    lb.addEventListener('pointerdown', (e) => {
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      downOnBackground = e.target === lb;
      // Capture only for drags on the image. Pointer capture retargets the
      // synthetic click to the overlay, which would otherwise close the lightbox.
      if (!downOnBackground && e.target.tagName === 'IMG') {
        try { lb.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      }
    });
    lb.addEventListener('pointermove', (e) => {
      const prev = pointers.get(e.pointerId);
      if (!prev) return;
      if (pointers.size === 1) {
        tx += e.clientX - prev.x;
        ty += e.clientY - prev.y;
        apply();
      } else if (pointers.size === 2) {
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        const [a, b] = [...pointers.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (pinchDist) {
          const c = centerOffset((a.x + b.x) / 2, (a.y + b.y) / 2);
          zoomAt(c.x, c.y, d / pinchDist);
        }
        pinchDist = d;
      }
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    });
    const release = (e) => {
      pointers.delete(e.pointerId);
      if (pointers.size < 2) pinchDist = null;
    };
    lb.addEventListener('pointerup', release);
    lb.addEventListener('pointercancel', release);

    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => {
      document.removeEventListener('keydown', onKey);
      lb.remove();
    };
    document.addEventListener('keydown', onKey);
    lb.addEventListener('click', (e) => { if (downOnBackground && e.target === lb) close(); });
    lb.querySelector('[data-act="close"]').addEventListener('click', close);
    lb.querySelector('[data-act="in"]').addEventListener('click', () => zoomAt(0, 0, 1.5));
    lb.querySelector('[data-act="out"]').addEventListener('click', () => zoomAt(0, 0, 1 / 1.5));
    lb.querySelector('[data-act="reset"]').addEventListener('click', () => { scale = 1; tx = 0; ty = 0; apply(); });
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
    hostGuessMarkers.clear();
    hostViewCircles.clear();
  }

  // ---------- state ----------
  async function loadState() {
    try {
      const data = await api('GET', `/api/rooms/${state.uuid}/state`);
      state.room = data.room;
      state.players = data.players;
      state.round = data.round;
      syncHostChannel();
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
        ${!p.isHost ? `<span class="score">${p.totalScore || 0} pts</span>` : ''}
      </span>`).join('');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Wires the #photo <img> (present in both the playing and finished views)
  // to the round's photo, with click-to-zoom. No-ops without a photo/element.
  function mountPhoto(photoUrl) {
    const url = photoSrc(photoUrl);
    const img = document.getElementById('photo');
    if (!url || !img) return;
    img.src = url;
    img.onerror = () => { img.alt = 'Photo unavailable'; };
    img.addEventListener('click', () => openLightbox(url));
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
              ? '<span class="muted">You set the location — player pins and where they are looking appear on the map in real time.</span>'
              : '<button id="submit-btn" disabled>Submit guess</button><span class="muted">Click the map to drop your pin. Click the photo to zoom.</span>'}
            ${host ? '<button id="reveal-btn" class="secondary">Reveal now</button>' : ''}
          </div>
        </div>
        <div class="map" id="map"></div>
      </div>`;

    mountPhoto(round.photoUrl);

    initGameMap(host);
    // The host watches: show where players have dropped their pins and where
    // they are currently looking (a circle around each player's viewport).
    if (host) {
      if (Array.isArray(round.guesses)) {
        for (const g of round.guesses) {
          hostGuessMarkers.set(g.id, L.marker([g.lat, g.lng]).addTo(gameMap).bindTooltip(escapeHtml(g.name)));
        }
      }
      if (Array.isArray(round.views)) {
        for (const v of round.views) upsertViewCircle(v);
      }
    }
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
    mapTiles().addTo(gameMap);

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
    // Let the host see where I am looking (throttled).
    startViewReporting();
  }

  // Leaflet lets the map pan continuously past +/-180° longitude, so a pin
  // placed (or the map center) after scrolling far west/east comes back
  // "unwrapped" — e.g. -220 instead of its Japan-equivalent 140. The marker
  // itself is left as-is (so it stays exactly where the user clicked/dragged,
  // with no visual jump); this only normalizes the value sent to the server,
  // which validates longitude within -180..180.
  function wrapLng(lat, lng) {
    return L.latLng(lat, lng).wrap();
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
      const pinWrapped = wrapLng(myPin.lat, myPin.lng);
      await api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/guess`, { lat: pinWrapped.lat, lng: pinWrapped.lng });
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

  // Report where I am looking on the map so the host can follow my search.
  // Throttled to ~1 update/second, with a trailing send for the final position.
  function startViewReporting() {
    if (isHost() || !gameMap) return;
    let lastSent = 0;
    let trailing = null;

    const send = () => {
      if (!state.round || state.round.status !== 'playing') return;
      const c = wrapLng(gameMap.getCenter().lat, gameMap.getCenter().lng);
      api('POST', `/api/rooms/${state.uuid}/rounds/${state.round.id}/view`, {
        lat: c.lat,
        lng: c.lng,
        zoom: gameMap.getZoom(),
        widthPx: gameMap.getSize().x,
      }).catch(() => { /* best effort — the host just misses an update */ });
    };

    gameMap.on('moveend', () => {
      if (Date.now() - lastSent >= 1000) {
        lastSent = Date.now();
        send();
      } else if (!trailing) {
        trailing = setTimeout(() => {
          trailing = null;
          lastSent = Date.now();
          send();
        }, 1000);
      }
    });

    send(); // initial report so the host sees me right away
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
    const hasPhoto = !!round.photoUrl;
    game.innerHTML = `
      <div class="card">
        <h2>Results</h2>
        <p class="muted">The true location is revealed. Closest guess wins.${hasPhoto ? ' Click the photo to zoom in.' : ''}</p>
        <div class="${hasPhoto ? 'grid finished' : ''}" style="margin-bottom:16px">
          ${hasPhoto ? '<div class="photo-wrap"><img id="photo" alt="Where was this photo taken?"></div>' : ''}
          <div class="map" id="map"></div>
        </div>
        <table>
          <thead><tr><th>#</th><th>Player</th><th>Distance</th><th>Score</th><th>Total</th></tr></thead>
          <tbody id="results-body"></tbody>
        </table>
        <p class="muted" style="margin-top:8px">Score is this round; Total adds up across every round played in this room.</p>
        <div id="host-controls" style="margin-top:16px">${hostControls()}</div>
      </div>`;
    mountPhoto(round.photoUrl);
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
    mapTiles().addTo(gameMap);

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
        <td>${r.total}</td>
      </tr>`;
    }).join('');
  }

  // ---------- host controls ----------
  function hostControls() {
    if (!isHost()) return '';
    const status = state.round ? state.round.status : null;
    if (!state.round || status === 'finished') {
      const defaultDuration = state.room ? state.room.defaultDuration : 300;
      const durationOptions = [
        [60, '1 min'], [120, '2 min'], [180, '3 min'], [300, '5 min'], [600, '10 min'], [0, 'unlimited'],
      ].map(([value, label]) => `<option value="${value}"${value === defaultDuration ? ' selected' : ''}>${label}</option>`).join('');
      return `
        <div class="host-panel">
          <h3>${state.round ? 'Next round' : 'New round'}</h3>
          <p class="privacy-note">The photo is stored on our server and is only accessible to people who have this room's link. It's deleted automatically a week after the round ends.</p>
          <div class="file-row">
            <input type="file" id="round-photo" accept="image/*">
            <select id="duration" title="Time limit">${durationOptions}</select>
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
      const fileInput = document.getElementById('round-photo');
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
    mapTiles().addTo(targetMap);
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
    const file = document.getElementById('round-photo').files[0];
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
      const targetWrapped = wrapLng(target.lat, target.lng);
      fd.append('lat', targetWrapped.lat);
      fd.append('lng', targetWrapped.lng);
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
      // Private channel auth (host pin view) goes through Laravel's
      // broadcasting/auth endpoint with the player token.
      authEndpoint: location.origin + '/broadcasting/auth',
      auth: { headers: { 'X-Player-Token': state.token } },
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

  // The host subscribes to a private channel to watch pins live.
  // (Auth for regular players is rejected server-side, so they never get it.)
  function syncHostChannel() {
    if (!pusher) return;
    if (isHost() && !hostChannel) {
      hostChannel = pusher.subscribe('private-room-' + state.uuid + '.host');
      hostChannel.bind('round.guess-placed', onGuessPlaced);
      hostChannel.bind('round.view-updated', (data) => {
        if (state.round && data.roundId === state.round.id) upsertViewCircle(data);
      });
    } else if (!isHost() && hostChannel) {
      pusher.unsubscribe('private-room-' + state.uuid + '.host');
      hostChannel = null;
    }
  }

  function onGuessPlaced(data) {
    if (!state.round || data.roundId !== state.round.id || !gameMap) return;
    let marker = hostGuessMarkers.get(data.playerId);
    if (marker) {
      marker.setLatLng([data.lat, data.lng]);
    } else {
      marker = L.marker([data.lat, data.lng]).addTo(gameMap).bindTooltip(escapeHtml(data.name));
      hostGuessMarkers.set(data.playerId, marker);
    }
    // Keep the new pin in view.
    if (!gameMap.getBounds().contains(marker.getLatLng())) {
      gameMap.fitBounds(gameMap.getBounds().extend(marker.getLatLng()).pad(0.3));
    }
    const el = document.getElementById('tally');
    if (el) el.textContent = `${data.submitted} of ${data.total} submitted`;
  }

  // ---------- host view circles ----------
  // The host sees a circle around each player's current viewport — a rough
  // "they are searching this area" indicator derived from center + zoom.
  const VIEW_COLORS = ['#2dd4bf', '#ff8a3d', '#f472b6', '#a78bfa', '#facc15', '#4ade80', '#60a5fa', '#fb923c'];

  function viewColor(playerId) {
    const idx = state.players.findIndex((p) => p.id === playerId);
    return VIEW_COLORS[(idx < 0 ? 0 : idx) % VIEW_COLORS.length];
  }

  // Web Mercator: meters per screen pixel at a given latitude and zoom level.
  function metersPerPixel(lat, zoom) {
    return (156543.03392 * Math.cos((lat * Math.PI) / 180)) / Math.pow(2, zoom);
  }

  function upsertViewCircle(v) {
    if (!gameMap || v.lat == null || v.lng == null) return;
    const zoom = v.zoom ?? gameMap.getZoom();
    const widthPx = v.widthPx || gameMap.getSize().x;
    const radius = metersPerPixel(v.lat, zoom) * (widthPx / 2);

    let circle = hostViewCircles.get(v.playerId);
    if (circle) {
      circle.setLatLng([v.lat, v.lng]);
      circle.setRadius(radius);
    } else {
      const color = viewColor(v.playerId);
      circle = L.circle([v.lat, v.lng], {
        radius,
        color,
        weight: 2,
        opacity: 0.75,
        fillColor: color,
        fillOpacity: 0.12,
      }).addTo(gameMap);
      hostViewCircles.set(v.playerId, circle);
    }
    if (circle.getTooltip()) circle.setTooltipContent(escapeHtml(v.name));
    else circle.bindTooltip(escapeHtml(v.name), { direction: 'center' });
    // Keep the player's search area in view (same as pins): only pan when
    // the player has jumped outside the current viewport.
    if (!gameMap.getBounds().contains(circle.getLatLng())) {
      gameMap.fitBounds(gameMap.getBounds().extend(circle.getLatLng()).pad(0.3));
    }
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
