/* FRAME PARING STORE — anteprima dello schermo nel browser.
   Riproduce la playlist reale (stessi metadati che riceve la TV) con le
   stesse transizioni del player Android: rotazione, fit contain/cover,
   durate, entrata/uscita, Ken Burns, immagine di cortesia fuori fascia.
   Scorrimento manuale avanti/indietro e pausa per ispezionare i parametri. */
(function () {
  'use strict';

  var root = document.getElementById('preview');
  if (!root) { return; }

  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var stage = root.querySelector('.pv-stage');
  var caption = root.querySelector('.pv-caption');
  var counter = root.querySelector('.pv-counter');
  var btnPrev = root.querySelector('[data-pv="prev"]');
  var btnNext = root.querySelector('[data-pv="next"]');
  var btnPlay = root.querySelector('[data-pv="play"]');
  var btnReload = root.querySelector('[data-pv="reload"]');
  var idleNote = root.querySelector('.pv-idle-note');

  var items = [];
  var idleItem = null;
  var index = 0;
  var playing = true;
  var timer = null;
  var currentEl = null;

  var RANDOM_POOL = ['fade', 'crossfade', 'slide_left', 'slide_right',
                     'slide_up', 'slide_down', 'zoom_in', 'zoom_out'];

  function resolveRandom(name) {
    return name === 'random'
      ? RANDOM_POOL[Math.floor(Math.random() * RANDOM_POOL.length)]
      : name;
  }

  /* ---------- geometria: stessa matematica del PlayerController ---------- */
  function applyTransform(el, contentW, contentH, rotation, fit) {
    var w = stage.clientWidth;
    var h = stage.clientHeight;
    if (!w || !h || !contentW || !contentH) { return; }

    var swap = rotation === 90 || rotation === 270;
    var boxW = swap ? contentH : contentW;
    var boxH = swap ? contentW : contentH;
    var scale = fit === 'cover'
      ? Math.max(w / boxW, h / boxH)
      : Math.min(w / boxW, h / boxH);

    el.style.width = (contentW * scale) + 'px';
    el.style.height = (contentH * scale) + 'px';
    el.dataset.rotation = String(rotation);
    el.style.setProperty('--rot', rotation + 'deg');
  }

  /* ---------- transizioni (equivalenti CSS di quelle Android) ---------- */
  function enterState(transition) {
    switch (transition) {
      case 'fade':
      case 'crossfade':
      case 'kenburns': return { opacity: 0, x: 0, y: 0, scale: 1 };
      case 'slide_left': return { opacity: 1, x: 100, y: 0, scale: 1 };
      case 'slide_right': return { opacity: 1, x: -100, y: 0, scale: 1 };
      case 'slide_up': return { opacity: 1, x: 0, y: 100, scale: 1 };
      case 'slide_down': return { opacity: 1, x: 0, y: -100, scale: 1 };
      case 'zoom_in': return { opacity: 0, x: 0, y: 0, scale: 0.6 };
      case 'zoom_out': return { opacity: 0, x: 0, y: 0, scale: 1.4 };
      default: return null; // cut
    }
  }

  function exitState(transition) {
    switch (transition) {
      case 'fade':
      case 'crossfade':
      case 'kenburns': return { opacity: 0, x: 0, y: 0, scale: 1 };
      case 'slide_left': return { opacity: 1, x: -100, y: 0, scale: 1 };
      case 'slide_right': return { opacity: 1, x: 100, y: 0, scale: 1 };
      case 'slide_up': return { opacity: 1, x: 0, y: -100, scale: 1 };
      case 'slide_down': return { opacity: 1, x: 0, y: 100, scale: 1 };
      case 'zoom_in': return { opacity: 0, x: 0, y: 0, scale: 1.4 };
      case 'zoom_out': return { opacity: 0, x: 0, y: 0, scale: 0.6 };
      default: return null; // cut
    }
  }

  function applyState(el, state, durationMs) {
    el.style.transition = durationMs
      ? 'opacity ' + durationMs + 'ms ease, transform ' + durationMs + 'ms ease'
      : 'none';
    if (!state) {
      el.style.opacity = '1';
      el.style.transform = 'translate(0,0) rotate(var(--rot,0deg)) scale(1)';
      return;
    }
    el.style.opacity = String(state.opacity);
    el.style.transform =
      'translate(' + state.x + '%,' + state.y + '%) rotate(var(--rot,0deg)) scale(' + state.scale + ')';
  }

  /* ---------- rendering di un item ---------- */
  function buildElement(item) {
    var el;
    if (item.type === 'video') {
      el = document.createElement('video');
      el.src = item.url;
      el.muted = item.muted !== false;
      el.autoplay = true;
      el.playsInline = true;
      el.loop = false;
    } else {
      el = document.createElement('img');
      el.src = item.url;
      el.alt = '';
    }
    el.className = 'pv-media';
    return el;
  }

  function describe(item) {
    var bits = [];
    bits.push(item.type === 'video' ? 'Video' : 'Immagine');
    if (item.rotation) { bits.push(item.rotation + '°'); }
    bits.push(item.fit === 'cover' ? 'crop' : 'contain');
    if (item.type === 'image') { bits.push(item.duration + 's'); }
    else { bits.push(item.muted ? 'muto' : 'con audio'); }
    bits.push('in: ' + item.transition_in + ' · out: ' + item.transition_out);
    bits.push(item.transition_ms + 'ms');
    return bits.join(' · ');
  }

  function show(item, direction) {
    var el = buildElement(item);
    var outgoing = currentEl;
    currentEl = el;
    stage.appendChild(el);

    var transIn = resolveRandom(item.transition_in);
    var transOut = resolveRandom(item.transition_out);
    var duration = item.transition_ms || 0;

    function sized(w, h) {
      applyTransform(el, w, h, item.rotation, item.fit);
      applyState(el, enterState(transIn), 0); // stato iniziale, senza animazione
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { applyState(el, null, duration); });
      });
      if (transIn === 'kenburns') {
        setTimeout(function () {
          if (currentEl !== el) { return; }
          el.style.transition = 'transform ' + (item.duration * 1000) + 'ms linear';
          el.style.transform =
            'translate(2%,-2%) rotate(var(--rot,0deg)) scale(1.08)';
        }, duration);
      }
      if (outgoing) {
        applyState(outgoing, exitState(transOut), duration);
        setTimeout(function () { outgoing.remove(); }, duration || 0);
      }
    }

    if (item.type === 'video') {
      el.addEventListener('loadedmetadata', function () {
        sized(el.videoWidth, el.videoHeight);
      }, { once: true });
      el.addEventListener('ended', function () { if (playing) { next(1); } });
    } else {
      el.addEventListener('load', function () {
        sized(el.naturalWidth, el.naturalHeight);
      }, { once: true });
    }

    caption.textContent = describe(item);
    counter.textContent = (index + 1) + ' / ' + items.length;

    clearTimeout(timer);
    if (playing && item.type === 'image') {
      timer = setTimeout(function () { next(1); }, duration + item.duration * 1000);
    }
  }

  function next(step) {
    if (!items.length) { return; }
    index = (index + step + items.length) % items.length;
    show(items[index], step);
  }

  function setPlaying(on) {
    playing = on;
    btnPlay.textContent = on ? '❚❚ Pausa' : '▶ Riprendi';
    clearTimeout(timer);
    if (currentEl && currentEl.tagName === 'VIDEO') {
      if (on) { currentEl.play(); } else { currentEl.pause(); }
    }
    if (on) { next(0); }
  }

  /* ---------- caricamento ---------- */
  function load() {
    stage.innerHTML = '';
    currentEl = null;
    caption.textContent = 'Caricamento…';

    fetch('api.php?action=preview&screen_id=' + root.dataset.screen, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) { throw new Error(data.error); }
        items = data.items || [];
        idleItem = (data.schedule && data.schedule.idle_item) || null;

        root.classList.toggle('portrait', data.screen.orientation === 'portrait');

        var bands = (data.schedule && data.schedule.bands) || [];
        if (bands.length) {
          idleNote.textContent = 'Fasce attive: ' + bands.map(function (b) {
            return b.from + '–' + b.to;
          }).join(', ') + ' · fuori fascia: ' +
            (data.schedule.idle_mode === 'image' ? 'immagine di cortesia' : 'schermo nero');
          idleNote.hidden = false;
        } else {
          idleNote.hidden = true;
        }

        if (!items.length) {
          caption.textContent = 'Nessun contenuto assegnato a questo schermo';
          counter.textContent = '0 / 0';
          return;
        }
        index = 0;
        show(items[0], 1);
      })
      .catch(function (e) { caption.textContent = 'Errore: ' + e.message; });
  }

  btnPrev.addEventListener('click', function () { setPlaying(false); next(-1); });
  btnNext.addEventListener('click', function () { setPlaying(false); next(1); });
  btnPlay.addEventListener('click', function () { setPlaying(!playing); });
  btnReload.addEventListener('click', load);
  window.addEventListener('resize', function () {
    if (currentEl) {
      var item = items[index];
      var w = currentEl.tagName === 'VIDEO' ? currentEl.videoWidth : currentEl.naturalWidth;
      var h = currentEl.tagName === 'VIDEO' ? currentEl.videoHeight : currentEl.naturalHeight;
      applyTransform(currentEl, w, h, item.rotation, item.fit);
    }
  });

  load();
})();
