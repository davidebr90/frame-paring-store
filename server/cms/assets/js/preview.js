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
  var screenRotation = 0; // dello schermo mostrato ora, vedi displayRotation()

  var RANDOM_POOL = ['fade', 'crossfade', 'slide_left', 'slide_right',
                     'slide_up', 'slide_down', 'zoom_in', 'zoom_out'];

  function resolveRandom(name) {
    return name === 'random'
      ? RANDOM_POOL[Math.floor(Math.random() * RANDOM_POOL.length)]
      : name;
  }

  /* ---------- banner: stessa matematica di BannerController (Android) ----------
     Le nove posizioni disposte in senso orario lungo il perimetro: usata per
     "ruotare" una posizione semantica senza toccare geometria/ancoraggio. */
  var COMPASS = ['top_left', 'top_center', 'top_right', 'middle_right',
                 'bottom_right', 'bottom_center', 'bottom_left', 'middle_left'];

  // Frazioni di ancoraggio orizzontale/verticale (0=inizio, .5=centro, 1=fine),
  // equivalenti delle combinazioni Gravity usate in BannerController.kt
  var ANCHOR = {
    top_left: [0, 0], top_center: [.5, 0], top_right: [1, 0],
    middle_left: [0, .5], center: [.5, .5], middle_right: [1, .5],
    bottom_left: [0, 1], bottom_center: [.5, 1], bottom_right: [1, 1],
  };

  function rotatedPosition(position, screenRotation) {
    if (screenRotation === 0 || position === 'center') { return position; }
    var idx = COMPASS.indexOf(position);
    if (idx < 0) { return position; }
    var shift = Math.floor(screenRotation / 45) % COMPASS.length;
    return COMPASS[(idx + shift) % COMPASS.length];
  }

  var banner = null; // { config, screenId, box } — elementi vivi fra un load() e l'altro
  var bannerTick = null;

  function ensureBannerBox() {
    if (banner && banner.box) { return banner.box; }
    var box = document.createElement('div');
    box.className = 'pv-banner';
    var logo = document.createElement('img');
    logo.className = 'pv-banner-logo';
    var text = document.createElement('span');
    text.className = 'pv-banner-text';
    box.appendChild(logo);
    box.appendChild(text);
    stage.appendChild(box);
    return box;
  }

  function bannerText(config) {
    var now = new Date();
    var parts = [];
    if (config.show_date) {
      var d = now.getDate(), m = now.getMonth(), y = now.getFullYear();
      if (config.date_format === 'long') {
        // Segue la lingua dell'interfaccia del CMS: è la stessa approssimazione
        // già in uso altrove nell'anteprima, non necessariamente quella dello
        // schermo (che l'app TV legge dalle proprie risorse localizzate)
        var monthName = new Intl.DateTimeFormat(document.documentElement.lang || 'it', { month: 'long' }).format(now);
        parts.push(d + ' ' + monthName + ' ' + y);
      } else {
        parts.push(String(d).padStart(2, '0') + '/' + String(m + 1).padStart(2, '0') + '/' + y);
      }
    }
    if (config.show_time) {
      parts.push(String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'));
    }
    return parts.join('  ·  ');
  }

  // Posiziona e ruota il riquadro: stessa idea di BannerController.apply().
  // Il box si ancora per il proprio CENTRO (non per uno spigolo), quindi la
  // rotazione attorno al centro non lo sposta — ma il PUNTO in cui centrarlo
  // va comunque calcolato con l'ingombro DOPO la rotazione (scambiato per
  // 90°/270°), altrimenti agli angoli il riquadro sporge oltre il margine
  // esattamente come succedeva su Android prima del guscio dimensionato.
  function positionBanner(box, config, screenRotation) {
    var position = rotatedPosition(config.position, screenRotation);
    var anchor = ANCHOR[position] || ANCHOR.bottom_right;
    // Rotazione del riquadro: verso OPPOSTO a screenRotation, non uguale —
    // screenRotation dice di quanto il pannello è girato in senso orario
    // rispetto al segnale, quindi per restare leggibile il contenuto va
    // predisegnato ruotato della stessa quantità in senso antiorario
    // (stessa matematica, stessa ragione, di BannerController.kt).
    var boxRotation = (360 - screenRotation) % 360;
    var swap = boxRotation === 90 || boxRotation === 270;
    var footprintW = swap ? box.offsetHeight : box.offsetWidth;
    var footprintH = swap ? box.offsetWidth : box.offsetHeight;

    var margin = Math.max(8, Math.round(Math.min(stage.clientWidth, stage.clientHeight) * 0.035));
    var cx = anchor[0] === .5 ? stage.clientWidth / 2
      : anchor[0] === 0 ? margin + footprintW / 2
      : stage.clientWidth - margin - footprintW / 2;
    var cy = anchor[1] === .5 ? stage.clientHeight / 2
      : anchor[1] === 0 ? margin + footprintH / 2
      : stage.clientHeight - margin - footprintH / 2;

    box.style.left = cx + 'px';
    box.style.top = cy + 'px';
    box.style.transform = 'translate(-50%,-50%) rotate(' + boxRotation + 'deg)';
  }

  function renderBanner(config, screenRotation, screenId) {
    clearInterval(bannerTick);
    if (!config) {
      if (banner && banner.box) { banner.box.remove(); }
      banner = null;
      return;
    }

    var box = ensureBannerBox();
    banner = { config: config, screenRotation: screenRotation, box: box };

    var scale = config.size === 'small' ? 0.75 : config.size === 'large' ? 1.5 : 1;
    box.style.padding = (7 * scale) + 'px ' + (11 * scale) + 'px';
    box.style.borderRadius = (7 * scale) + 'px';
    box.style.background = 'rgba(12,14,18,' + (Math.max(0, Math.min(100, config.opacity)) / 100) + ')';

    var logo = box.querySelector('.pv-banner-logo');
    if (config.logo_url) {
      logo.style.display = '';
      logo.style.height = (22 * scale) + 'px';
      logo.style.marginRight = config.show_date || config.show_time ? (8 * scale) + 'px' : '0';
      // Finché il logo non è caricato la sua larghezza reale è sconosciuta:
      // senza questo, il primo posizionamento misurerebbe un riquadro più
      // stretto di quello vero e lo piazzerebbe nel punto sbagliato
      logo.onload = function () { positionBanner(box, config, screenRotation); };
      logo.src = 'banner_logo.php?screen=' + (screenId || 0) + '&v=' + (config.logo_tag || '');
    } else {
      logo.style.display = 'none';
      logo.onload = null;
      logo.removeAttribute('src');
    }

    var text = box.querySelector('.pv-banner-text');
    text.style.fontSize = (15 * scale) + 'px';

    function tick() {
      var content = bannerText(config);
      text.textContent = content;
      text.style.display = content ? '' : 'none';
      box.style.display = (content === '' && !config.logo_url) ? 'none' : '';
      // Riposiziona a ogni ricalcolo: le dimensioni del box possono
      // cambiare con il testo (es. secondi che cambiano larghezza cifre)
      positionBanner(box, config, screenRotation);
    }
    tick();
    bannerTick = setInterval(tick, 20000);
  }

  /* ---------- rotazione da mostrare: "svela" solo la parte utile al viewer ----------
     item.rotation (dalla API, identica a quella che riceve la TV) è pensata
     per un buffer di rendering SEMPRE orizzontale: compone la compensazione
     di montaggio (che la TV applica per intero, poi il pannello fisico la
     "disfa" ruotando davvero) con l'eventuale correzione di orientamento
     della singola foto (vedi effective_item_rotation in core/screens.php).
     Qui in pagina non c'è un pannello fisico che ruota — il .pv-frame è già
     disegnato con la FORMA finale che il viewer vede (verticale quando lo
     schermo lo è, vedi il toggle di .portrait più sotto) — quindi applicare
     la rotazione intera ruoterebbe il contenuto UNA VOLTA DI TROPPO: la si
     scompone e si tiene solo la correzione di orientamento, sottraendo la
     compensazione di montaggio (calcolabile qui perché screen.rotation
     arriva già nella risposta).
  */
  function displayRotation(itemRotation) {
    var mountCompensation = (360 - screenRotation) % 360;
    return ((itemRotation - mountCompensation) % 360 + 360) % 360;
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
    var rot = displayRotation(item.rotation);
    if (rot) { bits.push(rot + '°'); }
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
      applyTransform(el, w, h, displayRotation(item.rotation), item.fit);
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
    clearInterval(bannerTick);
    banner = null; // il nodo appena svuotato da innerHTML non esiste più
    caption.textContent = 'Caricamento…';

    // Entrambi presenti: anteprima di questa galleria SU quello schermo
    // reale (rotazione vera, non quella "di comodo" del solo screen_id=0)
    var galleryId = Number(root.dataset.gallery) || 0;
    var screenId = Number(root.dataset.screen) || 0;
    var query = galleryId > 0 && screenId > 0
      ? 'gallery_id=' + galleryId + '&screen_id=' + screenId
      : screenId > 0
        ? 'screen_id=' + screenId
        : 'gallery_id=' + galleryId;

    fetch('api.php?action=preview&' + query, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) { throw new Error(data.error); }
        items = data.items || [];
        idleItem = (data.schedule && data.schedule.idle_item) || null;

        root.classList.toggle('portrait', data.screen.orientation === 'portrait');
        screenRotation = (data.screen && data.screen.rotation) || 0;
        renderBanner(data.banner, screenRotation, data.screen && data.screen.id);

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

  // Selettore schermo (scheda Contenuti): cambia data-screen e ricarica —
  // stessa galleria, rotazione ricalcolata per lo schermo scelto
  var screenPicker = document.getElementById('preview-screen-select');
  if (screenPicker) {
    screenPicker.addEventListener('change', function () {
      root.dataset.screen = screenPicker.value;
      setPlaying(false);
      load();
    });
  }

  window.addEventListener('resize', function () {
    if (currentEl) {
      var item = items[index];
      var w = currentEl.tagName === 'VIDEO' ? currentEl.videoWidth : currentEl.naturalWidth;
      var h = currentEl.tagName === 'VIDEO' ? currentEl.videoHeight : currentEl.naturalHeight;
      applyTransform(currentEl, w, h, displayRotation(item.rotation), item.fit);
    }
    if (banner) {
      positionBanner(banner.box, banner.config, banner.screenRotation);
    }
  });

  load();
})();
