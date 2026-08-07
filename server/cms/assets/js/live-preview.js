/*
 * Anteprima della diretta.
 *
 * Una cornice per ogni schermo scelto, con il suo rapporto e le sue
 * impostazioni: lo stesso contenuto su un pannello orizzontale e su uno
 * verticale non si comporta allo stesso modo, e senza vederlo prima ci si
 * accorge del ritaglio solo a diretta avviata su tutte le vetrine.
 *
 * La geometria è la stessa del PlayerController Android e di preview.js:
 * si ruota il contenuto, si calcola l'ingombro dopo la rotazione e lo si
 * scala per contenerlo (contain) o per riempire (cover).
 */
(function () {
  var root = document.querySelector('[data-live-preview]');
  if (!root) { return; }

  var media     = JSON.parse(root.dataset.media || '{}');
  var screens   = JSON.parse(root.dataset.screens || '{}');
  var galleries = JSON.parse(root.dataset.galleries || '{}');
  var L         = JSON.parse(root.dataset.labels || '{}');
  var frames    = root.querySelector('[data-lv-frames]');
  var note      = root.querySelector('[data-lv-note]');

  var giroGalleria = null;   // timer che fa scorrere l'anteprima di una galleria
  var indiceGalleria = 0;

  // Stessa matematica del player: l'ingombro a schermo è quello DOPO la
  // rotazione, ed è su quello che si calcola la scala.
  function applicaTrasformazione(el, contenutoW, contenutoH, rotazione, fit) {
    var stage = el.parentNode;
    if (!stage) { return; }
    var w = stage.clientWidth;
    var h = stage.clientHeight;
    if (!w || !h || !contenutoW || !contenutoH) { return; }

    var ruotato = rotazione === 90 || rotazione === 270;
    var boxW = ruotato ? contenutoH : contenutoW;
    var boxH = ruotato ? contenutoW : contenutoH;
    var scala = fit === 'cover'
      ? Math.max(w / boxW, h / boxH)
      : Math.min(w / boxW, h / boxH);

    el.style.width  = (contenutoW * scala) + 'px';
    el.style.height = (contenutoH * scala) + 'px';
    el.style.setProperty('--rot', rotazione + 'deg');
  }

  function valore(id) {
    var el = document.getElementById(id);
    return el ? el.value : '';
  }

  function stato() {
    var tipo = (document.querySelector('[data-source]:checked') || {}).value || 'media';

    // Ogni schermo scelto porta con sé le proprie impostazioni: quelle
    // vuote ricadono sui valori della diretta
    var fitDiretta = valore('fit') || 'contain';
    var rotDiretta = parseInt(valore('rotation') || '0', 10);

    var scelti = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-screen-check]'), function (cb) {
      if (!cb.checked) { return; }
      var info = screens[cb.value];
      if (!info) { return; }
      var f = (document.querySelector('[data-screen-fit="' + cb.value + '"]') || {}).value || '';
      var r = (document.querySelector('[data-screen-rot="' + cb.value + '"]') || {}).value || '';
      scelti.push({
        id: cb.value,
        nome: info.name,
        orientamento: info.orientation,
        fit: f || fitDiretta,
        rot: r === '' ? rotDiretta : parseInt(r, 10),
        proprio: !!(f || r !== '')
      });
    });

    return {
      tipo: tipo,
      mediaId: valore('media_id'),
      galleriaId: valore('gallery_id'),
      url: valore('source_url'),
      fitDiretta: fitDiretta,
      rotDiretta: rotDiretta,
      scelti: scelti
    };
  }

  // Con una galleria si mostra un file per volta, a rotazione: è ciò che
  // faranno gli schermi, e vedere solo il primo non direbbe granché
  function contenutoCorrente(st) {
    if (st.tipo === 'gallery') {
      var elenco = galleries[st.galleriaId] || [];
      if (!elenco.length) { return null; }
      return String(elenco[indiceGalleria % elenco.length]);
    }
    return st.mediaId || null;
  }

  function costruisciContenuto(st, stage, schermo) {
    if (st.tipo === 'external') {
      stage.innerHTML = '<div class="lv-placeholder">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
        + ' stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M12 12m-3 0a3 3 0 106 0 3 3 0 10-6 0M5.6 5.6a9 9 0 000 12.8M18.4 5.6a9 9 0 010 12.8"/></svg>'
        + '<span></span></div>';
      stage.querySelector('span').textContent = st.url || '—';
      return;
    }

    var mediaId = contenutoCorrente(st);
    var info = mediaId ? media[mediaId] : null;
    if (!info) {
      stage.innerHTML = '<div class="lv-placeholder"><span></span></div>';
      stage.querySelector('span').textContent = L.no_content;
      return;
    }

    // Con una galleria comandano le impostazioni del singolo file, non
    // quelle della diretta: è così che la riprodurrà la TV
    var fit = st.tipo === 'gallery' ? (info.fit || 'contain') : schermo.fit;
    var rot = st.tipo === 'gallery' ? (info.rotation || 0) : schermo.rot;

    var src = 'media_preview.php?id=' + encodeURIComponent(mediaId);
    var el;
    if (info.type === 'video') {
      el = document.createElement('video');
      el.src = src;
      el.muted = true;          // mai audio a sorpresa dentro il pannello
      el.loop = true;
      el.autoplay = true;
      el.playsInline = true;
      el.addEventListener('loadedmetadata', function () {
        applicaTrasformazione(el, el.videoWidth, el.videoHeight, rot, fit);
      });
    } else {
      el = document.createElement('img');
      el.src = src;
      el.alt = '';
      el.addEventListener('load', function () {
        applicaTrasformazione(el, el.naturalWidth, el.naturalHeight, rot, fit);
      });
    }
    el.className = 'lv-media';
    stage.innerHTML = '';
    stage.appendChild(el);
  }

  function disegna() {
    var st = stato();
    // Nessuno schermo scelto: un pannello di esempio, per non lasciare il vuoto
    var pannelli = st.scelti.length ? st.scelti : [{
      nome: '', orientamento: 'landscape', fit: st.fitDiretta, rot: st.rotDiretta,
      proprio: false, esempio: true
    }];

    frames.innerHTML = '';
    pannelli.forEach(function (schermo) {
      var wrap = document.createElement('div');
      wrap.className = 'lv-frame-wrap' + (schermo.orientamento === 'portrait' ? ' portrait' : '');

      var etichetta = document.createElement('div');
      etichetta.className = 'lv-frame-label';
      var orient = schermo.orientamento === 'portrait' ? L.portrait : L.landscape;
      etichetta.textContent = schermo.esempio ? orient : (schermo.nome + ' · ' + orient);
      if (schermo.proprio) {
        var tag = document.createElement('span');
        tag.className = 'lv-own';
        tag.textContent = L.own;
        etichetta.appendChild(tag);
      }
      wrap.appendChild(etichetta);

      var frame = document.createElement('div');
      frame.className = 'lv-frame' + (schermo.orientamento === 'portrait' ? ' portrait' : '')
        + (schermo.esempio ? ' esempio' : '');
      var stage = document.createElement('div');
      stage.className = 'lv-stage';
      frame.appendChild(stage);
      wrap.appendChild(frame);
      frames.appendChild(wrap);

      costruisciContenuto(st, stage, schermo);
    });

    // Note: solo quelle che servono davvero in questo momento
    var righe = [];
    if (!st.scelti.length) { righe.push(L.no_screens); }
    if (st.tipo === 'external') {
      righe.push(L.external);
    } else if (st.tipo === 'gallery') {
      righe.push(L.gallery);
    } else {
      var m = media[st.mediaId];
      if (st.scelti.some(function (s) { return s.fit === 'cover'; })) { righe.push(L.crop); }
      if (m && m.type === 'video') { righe.push(L.audio); }
    }
    note.textContent = righe.join(' ');
    note.hidden = righe.length === 0;

    // Il giro della galleria riparte solo quando serve davvero
    clearInterval(giroGalleria);
    giroGalleria = null;
    if (st.tipo === 'gallery' && (galleries[st.galleriaId] || []).length > 1) {
      giroGalleria = setInterval(function () {
        indiceGalleria++;
        disegna();
      }, 3500);
    }
  }

  // Cambiare sorgente o galleria riparte dal primo file
  function disegnaDaCapo() {
    indiceGalleria = 0;
    disegna();
  }

  ['media_id', 'gallery_id', 'source_url', 'fit', 'rotation'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener('change', disegnaDaCapo);
      el.addEventListener('input', disegnaDaCapo);
    }
  });
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-screen-check], [data-source]')) { disegnaDaCapo(); }
    if (ev.target.matches('[data-screen-fit], [data-screen-rot]')) { disegna(); }
  });
  // I pulsanti "seleziona tutti" / "deseleziona" cambiano le caselle da JS,
  // che non emette l'evento change: si ridisegna dopo il loro click
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-select-all], [data-select-none]')) {
      setTimeout(disegnaDaCapo, 0);
    }
  });
  window.addEventListener('resize', disegna);

  disegnaDaCapo();
})();
