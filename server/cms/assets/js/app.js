/* FRAME PARING STORE — CMS. Vanilla JS: fetch con CSRF, drag & drop
   (Sortable.js), auto-save dei metadati media, upload con progress. */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  /* ---------- Toast ---------- */
  var toastEl = document.createElement('div');
  toastEl.className = 'toast';
  document.body.appendChild(toastEl);
  var toastTimer = null;

  function toast(message, isError) {
    toastEl.textContent = message;
    toastEl.classList.toggle('err', !!isError);
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2600);
  }

  /* ---------- API ---------- */
  function api(action, data) {
    return fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF
      },
      body: JSON.stringify(data || {})
    }).then(function (res) {
      // Una risposta non-JSON (pagina d'errore del server, proxy, timeout
      // dell'hosting) non deve produrre un "Unexpected token" indecifrabile
      return res.json().catch(function () {
        throw new Error('HTTP ' + res.status);
      }).then(function (json) {
        if (!res.ok) { throw new Error(json.error || 'HTTP ' + res.status); }
        return json;
      });
    });
  }

  /* ---------- Drag & drop dei media ---------- */
  var mediaGrid = document.getElementById('media-grid');
  if (mediaGrid && window.Sortable) {
    new Sortable(mediaGrid, {
      animation: 160,
      handle: '.media-thumb',
      onEnd: function () {
        var ids = Array.prototype.map.call(
          mediaGrid.querySelectorAll('.media-card'),
          function (el) { return parseInt(el.dataset.id, 10); }
        );
        api('media_reorder', { gallery_id: parseInt(mediaGrid.dataset.gallery, 10), ids: ids })
          .then(function () { toast('Ordine salvato'); })
          .catch(function (e) { toast(e.message, true); });
      }
    });
  }

  /* ---------- Auto-save metadati media (rotation / fit / durata) ---------- */
  document.addEventListener('change', function (ev) {
    var input = ev.target.closest('[data-media-field]');
    if (!input) { return; }
    var card = input.closest('.media-card');
    var dot = card.querySelector('.save-dot');
    var payload = { id: parseInt(card.dataset.id, 10) };
    payload[input.dataset.mediaField] = input.value;

    dot.className = 'save-dot saving';
    api('media_update', payload)
      .then(function () {
        dot.className = 'save-dot saved';
        var thumb = card.querySelector('.media-thumb');
        if (input.dataset.mediaField === 'rotation') { thumb.dataset.rot = input.value; }
        if (input.dataset.mediaField === 'fit') { thumb.dataset.fit = input.value; }
        setTimeout(function () { dot.className = 'save-dot'; }, 1500);
      })
      .catch(function (e) { dot.className = 'save-dot'; toast(e.message, true); });
  });

  /* ---------- Eliminazione media ---------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-media-delete]');
    if (!btn) { return; }
    if (!window.confirm('Eliminare definitivamente questo file?')) { return; }
    var card = btn.closest('.media-card');
    api('media_delete', { id: parseInt(card.dataset.id, 10) })
      .then(function () { card.remove(); toast('File eliminato'); })
      .catch(function (e) { toast(e.message, true); });
  });

  /* ---------- Upload (click + drag sulla dropzone) ---------- */
  var dropzone = document.getElementById('dropzone');
  if (dropzone) {
    var fileInput = dropzone.querySelector('input[type=file]');
    var dzLabel = dropzone.querySelector('.dz-label');

    // Il click sull'input non deve ribollire fino alla dropzone (ricorsione)
    fileInput.addEventListener('click', function (ev) { ev.stopPropagation(); });
    dropzone.addEventListener('click', function (ev) {
      if (ev.target === fileInput) { return; }
      fileInput.click();
    });
    ['dragover', 'dragenter'].forEach(function (t) {
      dropzone.addEventListener(t, function (ev) { ev.preventDefault(); dropzone.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      dropzone.addEventListener(t, function (ev) { ev.preventDefault(); dropzone.classList.remove('drag'); });
    });
    dropzone.addEventListener('drop', function (ev) { uploadFiles(ev.dataTransfer.files); });
    fileInput.addEventListener('change', function () { uploadFiles(fileInput.files); });

    function uploadFiles(files) {
      if (!files.length) { return; }
      var queue = Array.prototype.slice.call(files);
      var done = 0, failed = 0;

      function next() {
        if (!queue.length) {
          // Se qualcosa è fallito NON si ricarica subito: l'utente deve poter
          // leggere quali file non sono passati e perché
          if (failed) {
            dzLabel.textContent = failed + ' file non caricati (' + done + ' ok) — clicca per riprovare, oppure ricarica la pagina';
            toast(failed + ' file non caricati', true);
            return;
          }
          window.location.reload();
          return;
        }
        var file = queue.shift();
        dzLabel.textContent = 'Caricamento ' + (done + failed + 1) + '/' + files.length + ' — ' + file.name;

        var form = new FormData();
        form.append('gallery_id', dropzone.dataset.gallery);
        form.append('file', file);

        fetch('api.php?action=upload', {
          method: 'POST',
          headers: { 'X-CSRF-Token': CSRF },
          body: form
        }).then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
          .then(function (r) {
            if (r.ok) { done++; } else { failed++; toast(file.name + ': ' + (r.j.error || 'errore'), true); }
            next();
          })
          .catch(function (err) {
            failed++;
            toast(file.name + ': ' + (err.message || 'errore di rete'), true);
            next();
          });
      }
      next();
    }
  }

  /* ---------- Gallerie assegnate a uno schermo ---------- */
  var galleryList = document.getElementById('screen-galleries');
  if (galleryList) {
    var screenId = parseInt(galleryList.dataset.screen, 10);

    function saveGalleries() {
      var ids = Array.prototype.map.call(
        galleryList.querySelectorAll('li'),
        function (el) { return parseInt(el.dataset.id, 10); }
      );
      return api('screen_galleries', { screen_id: screenId, gallery_ids: ids })
        .then(function () { toast('Assegnazione salvata'); })
        .catch(function (e) { toast(e.message, true); throw e; });
    }

    if (window.Sortable) {
      new Sortable(galleryList, { animation: 160, handle: '.drag', onEnd: saveGalleries });
    }

    // Rimozione con rollback: la riga torna al suo posto se il server rifiuta
    galleryList.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.remove');
      if (!btn) { return; }
      var li = btn.closest('li');
      var sibling = li.nextElementSibling;
      li.remove();
      saveGalleries().catch(function () {
        galleryList.insertBefore(li, sibling);
      });
    });

    var addSelect = document.getElementById('gallery-add');
    if (addSelect) {
      addSelect.addEventListener('change', function () {
        var opt = addSelect.selectedOptions[0];
        if (!opt || !opt.value) { return; }
        var li = document.createElement('li');
        li.dataset.id = opt.value;
        li.innerHTML = '<span class="drag">⠿</span><span class="grow"></span>' +
                       '<button type="button" class="remove" title="Rimuovi">×</button>';
        li.querySelector('.grow').textContent = opt.textContent;
        galleryList.appendChild(li);
        opt.remove();
        addSelect.value = '';
        saveGalleries();
      });
    }
  }

  /* ---------- Copia negli appunti ---------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) { return; }
    var input = document.querySelector(btn.dataset.copy);
    if (!input) { return; }
    navigator.clipboard.writeText(input.value).then(
      function () { toast('Copiato negli appunti'); },
      function () { input.select(); document.execCommand('copy'); toast('Copiato'); }
    );
  });

  /* ---------- Editor fasce orarie (dettaglio schermo) ---------- */
  /* ---------- Programmazione settimanale ----------
     Fasce con i giorni in cui valgono, più un'anteprima della settimana che
     si aggiorna mentre si modifica: si vede subito quando lo schermo resta
     acceso, senza dover salvare per scoprirlo. */
  var bandsBox = document.getElementById('bands');
  if (bandsBox) {
    var scheduleForm = document.getElementById('schedule-form');
    var idleModeSel = document.getElementById('idle_mode');
    var idleField = document.getElementById('idle-media-field');
    var weekGrid = document.getElementById('week-grid');
    var weekTotal = document.getElementById('week-total');

    var GIORNI = JSON.parse(bandsBox.dataset.days || '{}');
    var PRESETS = JSON.parse(bandsBox.dataset.presets || '{}');
    var L = JSON.parse(bandsBox.dataset.labels || '{}');
    var TUTTI = [1, 2, 3, 4, 5, 6, 7];

    function minuti(hhmm) {
      return parseInt(hhmm.slice(0, 2), 10) * 60 + parseInt(hhmm.slice(3, 5), 10);
    }

    // Stessa regola del server: una fascia che scavalca la mezzanotte
    // comincia nel giorno scelto e finisce il mattino dopo
    function fasciaAttiva(b, giorno, minuto) {
      var da = minuti(b.from), a = minuti(b.to);
      if (da === a) { return false; }
      if (da < a) {
        return b.days.indexOf(giorno) !== -1 && minuto >= da && minuto < a;
      }
      if (b.days.indexOf(giorno) !== -1 && minuto >= da) { return true; }
      var ieri = giorno === 1 ? 7 : giorno - 1;
      return b.days.indexOf(ieri) !== -1 && minuto < a;
    }

    function leggiFasce() {
      var out = [];
      Array.prototype.forEach.call(bandsBox.querySelectorAll('.band-row'), function (row) {
        var from = row.querySelector('.band-from').value;
        var to = row.querySelector('.band-to').value;
        if (!from || !to) { return; }
        var days = [];
        Array.prototype.forEach.call(row.querySelectorAll('.day-chip input'), function (cb) {
          if (cb.checked) { days.push(parseInt(cb.value, 10)); }
        });
        out.push({ from: from, to: to, days: days });
      });
      return out;
    }

    function aggiornaAnteprima() {
      if (!weekGrid) { return; }
      var fasce = leggiFasce().filter(function (b) { return b.days.length > 0; });
      var minutiAccesi = 0;

      Array.prototype.forEach.call(weekGrid.querySelectorAll('.week-grid-row'), function (row) {
        var giorno = parseInt(row.dataset.day, 10);
        Array.prototype.forEach.call(row.querySelectorAll('.cell'), function (cell) {
          var ora = parseInt(cell.dataset.hour, 10);
          var acceso = false;
          for (var m = 0; m < 60; m += 15) {
            if (fasce.length === 0 || fasce.some(function (b) { return fasciaAttiva(b, giorno, ora * 60 + m); })) {
              acceso = true; break;
            }
          }
          cell.classList.toggle('on', acceso);
        });
      });

      for (var g = 1; g <= 7; g++) {
        for (var m2 = 0; m2 < 1440; m2 += 5) {
          if (fasce.length === 0 || fasce.some(function (b) { return fasciaAttiva(b, g, m2); })) {
            minutiAccesi += 5;
          }
        }
      }
      if (weekTotal) {
        var ore = Math.round(minutiAccesi / 6) / 10;
        weekTotal.textContent = (weekGrid.dataset.hoursLabel || ':n').replace(':n', ore)
          + (fasce.length === 0 ? ' · ' + (L.none || '') : '');
      }
    }

    // Costruzione via DOM: i valori non passano mai da innerHTML
    function addBand(from, to, days) {
      var row = document.createElement('div');
      row.className = 'band-row';

      var orari = document.createElement('div');
      orari.className = 'band-times';

      function campoOra(classe, etichetta, valore) {
        var wrap = document.createElement('label');
        wrap.className = 'band-field';
        var lab = document.createElement('span');
        lab.textContent = etichetta;
        var input = document.createElement('input');
        input.type = 'time';
        input.className = classe;
        input.required = true;
        input.value = valore;
        input.addEventListener('input', function () { aggiornaAnteprima(); segnalaScavalco(); });
        wrap.append(lab, input);
        return wrap;
      }

      var campoDa = campoOra('band-from', L.from || 'Dalle', from || '09:00');
      var campoA = campoOra('band-to', L.to || 'Alle', to || '20:00');

      var nota = document.createElement('span');
      nota.className = 'band-note';
      nota.hidden = true;
      nota.textContent = L.midnight || '';

      function segnalaScavalco() {
        var f = row.querySelector('.band-from').value;
        var t = row.querySelector('.band-to').value;
        nota.hidden = !(f && t && minuti(f) > minuti(t));
      }

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'remove';
      remove.title = L.remove || '';
      remove.setAttribute('aria-label', L.remove || '');
      remove.textContent = '×';
      remove.addEventListener('click', function () { row.remove(); aggiornaAnteprima(); });

      orari.append(campoDa, campoA, nota, remove);

      var giorni = document.createElement('div');
      giorni.className = 'day-chips';
      var etichettaGiorni = document.createElement('span');
      etichettaGiorni.className = 'day-chips-label';
      etichettaGiorni.textContent = L.days || '';
      giorni.appendChild(etichettaGiorni);

      var scelti = days && days.length ? days : TUTTI;
      TUTTI.forEach(function (d) {
        var chip = document.createElement('label');
        chip.className = 'day-chip';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = d;
        cb.checked = scelti.indexOf(d) !== -1;
        cb.addEventListener('change', function () { aggiornaAnteprima(); });
        var txt = document.createElement('span');
        txt.textContent = GIORNI[d] || d;
        chip.append(cb, txt);
        giorni.appendChild(chip);
      });

      row.append(orari, giorni);
      bandsBox.appendChild(row);
      segnalaScavalco();
    }

    function caricaFasce(elenco) {
      bandsBox.textContent = '';
      (elenco || []).forEach(function (b) { addBand(b.from, b.to, b.days); });
      aggiornaAnteprima();
    }

    caricaFasce(JSON.parse(bandsBox.dataset.bands || '[]'));

    document.getElementById('band-add').addEventListener('click', function () {
      addBand();
      aggiornaAnteprima();
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-preset]'), function (btn) {
      btn.addEventListener('click', function () {
        caricaFasce(PRESETS[btn.dataset.preset] || []);
      });
    });

    scheduleForm.addEventListener('submit', function (ev) {
      var fasce = leggiFasce();
      var senzaGiorni = fasce.filter(function (b) { return b.days.length === 0; });
      if (senzaGiorni.length) {
        ev.preventDefault();
        alert(L.errDays || '');
        return;
      }
      document.getElementById('schedule-json').value = JSON.stringify(fasce);
    });

    // L'immagine di cortesia serve solo se si è scelto di mostrarne una
    if (idleModeSel && idleField) {
      var mostraIdle = function () {
        idleField.style.display = idleModeSel.value === 'image' ? '' : 'none';
      };
      idleModeSel.addEventListener('change', mostraIdle);
      mostraIdle();
    }

    /* Upload diretto dell'immagine di cortesia (galleria di servizio) */
    var idleBtn = document.getElementById('idle-upload-btn');
    var idleInput = document.getElementById('idle-upload-input');
    var idleSelect = document.getElementById('idle_media_id');
    var idlePreview = document.getElementById('idle-preview');

    function refreshIdlePreview() {
      if (idleSelect.value) {
        idlePreview.src = 'media_preview.php?id=' + idleSelect.value;
        idlePreview.style.display = '';
      } else {
        idlePreview.style.display = 'none';
      }
    }
    idleSelect.addEventListener('change', refreshIdlePreview);

    idleBtn.addEventListener('click', function () { idleInput.click(); });
    idleInput.addEventListener('change', function () {
      if (!idleInput.files.length) { return; }
      var form = new FormData();
      form.append('screen_id', idleBtn.dataset.screen);
      form.append('file', idleInput.files[0]);
      idleBtn.disabled = true;
      fetch('api.php?action=idle_upload', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: form
      }).then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
        .then(function (r) {
          if (!r.ok) { throw new Error(r.j.error || 'Errore di caricamento'); }
          var opt = document.createElement('option');
          opt.value = r.j.id;
          opt.textContent = r.j.label;
          opt.selected = true;
          idleSelect.appendChild(opt);
          idleModeSel.value = 'image';
          syncIdleField();
          refreshIdlePreview();
          toast('Immagine di cortesia caricata e attivata');
        })
        .catch(function (e) { toast(e.message, true); })
        .finally(function () { idleBtn.disabled = false; idleInput.value = ''; });
    });
  }

  /* ---------- Utenti: le capabilities spariscono per il ruolo admin ---------- */
  document.addEventListener('change', function (ev) {
    var select = ev.target.closest('[data-role-select]');
    if (!select) { return; }
    var form = select.closest('form');
    var caps = form.querySelector('[data-caps]') || form.querySelector('.caps-block');
    var note = form.querySelector('[data-admin-note]');
    var isAdmin = select.value === 'admin';
    if (caps) { caps.hidden = isAdmin; }
    if (note) { note.hidden = !isAdmin; }
  });

  /* ---------- Personalizzazione: color picker e campo esadecimale ---------- */
  var accentPicker = document.getElementById('brand_accent_picker');
  var accentText = document.getElementById('brand_accent');
  if (accentPicker && accentText) {
    accentPicker.addEventListener('input', function () { accentText.value = accentPicker.value; });
    accentText.addEventListener('input', function () {
      if (/^#[0-9a-fA-F]{6}$/.test(accentText.value)) { accentPicker.value = accentText.value; }
    });
  }

  /* ---------- Interruttori: la didascalia segue lo stato ---------- */
  document.addEventListener('change', function (ev) {
    var input = ev.target.closest('[data-switch]');
    if (!input) { return; }
    var state = input.closest('.switch').querySelector('[data-switch-state]');
    if (state) {
      state.textContent = input.checked ? state.dataset.on : state.dataset.off;
    }
  });

  /* ---------- Funzioni bloccate: diagnostica dei requisiti ---------- */
  var featureModal = document.getElementById('feature-modal');
  if (featureModal) {
    var lastFocused = null;

    function openFeatureModal(key) {
      var data = (window.FPS_FEATURES || {})[key];
      if (!data) { return; }
      lastFocused = document.activeElement;
      featureModal.querySelector('#feature-modal-title').textContent =
        data.label + ' — non disponibile su questa installazione';
      featureModal.querySelector('[data-modal-summary]').textContent = data.summary;

      var box = featureModal.querySelector('[data-modal-missing]');
      box.innerHTML = '';
      data.missing.forEach(function (m) {
        var row = document.createElement('div');
        row.className = 'req-line';
        var mark = document.createElement('span');
        mark.className = 'req-mark';
        mark.textContent = '✕';
        var text = document.createElement('span');
        var strong = document.createElement('strong');
        strong.textContent = m.label + ': ';
        var detail = document.createElement('span');
        detail.className = 'hint';
        detail.textContent = m.detail;
        text.append(strong, detail);
        if (m.howto) {
          var howto = document.createElement('div');
          howto.className = 'hint req-howto';
          howto.textContent = m.howto;
          text.appendChild(howto);
        }
        row.append(mark, text);
        box.appendChild(row);
      });

      featureModal.querySelector('[data-modal-note]').textContent =
        'Le altre funzioni del sistema continuano a lavorare normalmente.';
      featureModal.hidden = false;
      featureModal.querySelector('[data-modal-close]').focus();
    }

    function closeFeatureModal() {
      featureModal.hidden = true;
      if (lastFocused) { lastFocused.focus(); }
    }

    document.addEventListener('click', function (ev) {
      var locked = ev.target.closest('[data-feature]');
      if (locked) {
        ev.preventDefault();
        openFeatureModal(locked.dataset.feature);
        return;
      }
      if (ev.target.closest('[data-modal-close]') || ev.target === featureModal) {
        closeFeatureModal();
      }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !featureModal.hidden) { closeFeatureModal(); }
    });
  }

  /* ---------- Dirette: sorgente e selezione schermi ---------- */
  document.addEventListener('change', function (ev) {
    var radio = ev.target.closest('[data-source]');
    if (!radio) { return; }
    document.querySelectorAll('[data-source-panel]').forEach(function (panel) {
      panel.hidden = panel.dataset.sourcePanel !== radio.value;
    });
    // Con una galleria intera ogni file porta già il proprio adattamento e
    // la propria rotazione: offrirne altri qui creerebbe una doppia
    // trasformazione difficile da prevedere
    document.querySelectorAll('[data-not-gallery]').forEach(function (el) {
      el.hidden = radio.value === 'gallery';
    });
    // Anche gli scostamenti del singolo schermo non si applicano a una
    // galleria: mostrarli prometterebbe un effetto che non ci sarà.
    // Serve una classe, non l'attributo hidden: quello lo governa già la
    // casella di selezione dello schermo.
    document.body.classList.toggle('live-gallery', radio.value === 'gallery');
  });

  // Le impostazioni di uno schermo compaiono solo quando lo si sceglie:
  // mostrarle tutte trasformerebbe l'elenco in un muro di menu a tendina
  function aggiornaOpzioniSchermo(cb) {
    var box = document.querySelector('[data-screen-opts="' + cb.value + '"]');
    if (box) { box.hidden = !cb.checked; }
  }
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-screen-check]')) { aggiornaOpzioniSchermo(ev.target); }
  });
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-select-all], [data-select-none]')) {
      setTimeout(function () {
        document.querySelectorAll('[data-screen-check]').forEach(aggiornaOpzioniSchermo);
  // Stato iniziale: la sorgente selezionata nel markup deve governare i
  // blocchi fin dal caricamento, non solo dopo il primo cambio
  var sorgenteIniziale = document.querySelector('[data-source]:checked');
  if (sorgenteIniziale) {
    sorgenteIniziale.dispatchEvent(new Event('change', { bubbles: true }));
  }
      }, 0);
    }
  });
  document.querySelectorAll('[data-screen-check]').forEach(aggiornaOpzioniSchermo);
  // Stato iniziale: la sorgente selezionata nel markup deve governare i
  // blocchi fin dal caricamento, non solo dopo il primo cambio
  var sorgenteIniziale = document.querySelector('[data-source]:checked');
  if (sorgenteIniziale) {
    sorgenteIniziale.dispatchEvent(new Event('change', { bubbles: true }));
  }

  document.addEventListener('click', function (ev) {
    var all = ev.target.closest('[data-select-all]');
    var none = ev.target.closest('[data-select-none]');
    if (!all && !none) { return; }
    document.querySelectorAll('[data-screen-check]').forEach(function (cb) {
      cb.checked = !!all;
    });
  });

  /* ---------- Spia delle dirette nel menu ----------
     Si aggiorna da sola: senza questo aggiornamento una diretta avviata da
     un altro operatore resterebbe invisibile fino al ricaricamento. */
  var navDot = document.querySelector('.nav-dot');
  if (navDot) {
    var STATES = ['live', 'recent', 'error', 'idle'];
    setInterval(function () {
      if (document.hidden) { return; } // scheda in secondo piano: si risparmia
      fetch('live_status.php', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) {
          if (!s || STATES.indexOf(s.state) === -1) { return; }
          STATES.forEach(function (k) { navDot.classList.remove('nav-dot-' + k); });
          navDot.classList.add('nav-dot-' + s.state);
          navDot.title = s.label;
          navDot.setAttribute('aria-label', s.label);
        })
        .catch(function () { /* rete assente: si tiene l'ultimo stato noto */ });
    }, 20000);
  }

  /* ---------- Suggerimenti ----------
     L'attributo title non compare mai su un dispositivo touch, e dentro una
     tabella che scorre verrebbe comunque tagliato. Qui il suggerimento è un
     elemento fisso posizionato sull'elemento: si apre al passaggio del mouse,
     al tocco e da tastiera.
     Si aggancia al solo attributo data-tip: la classe .tip è l'aspetto del
     marcatore, e imporla a un elemento che ha già una sua forma — la
     striscia della settimana, un'icona — la deformerebbe. */
  var tipBox = null;
  var tipTarget = null;
  // Da cosa è stato aperto: al tocco il focus arriva PRIMA del click, e
  // senza distinguerli il click richiuderebbe subito ciò che il focus
  // ha appena aperto — il suggerimento non comparirebbe mai su telefono
  var tipSource = null;

  function tipHide() {
    if (tipBox) { tipBox.classList.remove('show'); }
    if (tipTarget) { tipTarget.setAttribute('aria-expanded', 'false'); }
    tipTarget = null;
    tipSource = null;
  }

  function tipShow(el, source) {
    var testo = el.dataset.tip;
    if (!testo) { return; }
    if (!tipBox) {
      tipBox = document.createElement('div');
      tipBox.className = 'tip-box';
      tipBox.setAttribute('role', 'tooltip');
      document.body.appendChild(tipBox);
    }
    tipBox.textContent = testo;
    tipBox.classList.add('show');
    tipTarget = el;
    tipSource = source;
    el.setAttribute('aria-expanded', 'true');

    // Posizionamento: sopra l'elemento, rientrato se sborderebbe
    var r = el.getBoundingClientRect();
    var b = tipBox.getBoundingClientRect();
    var left = r.left + r.width / 2 - b.width / 2;
    var margine = 8;
    left = Math.max(margine, Math.min(left, window.innerWidth - b.width - margine));
    var top = r.top - b.height - 10;
    var sotto = top < margine;
    if (sotto) { top = r.bottom + 10; }
    tipBox.style.left = left + 'px';
    tipBox.style.top = top + 'px';
    tipBox.classList.toggle('below', sotto);
  }

  document.addEventListener('mouseover', function (ev) {
    var el = ev.target.closest('[data-tip]');
    if (el && el !== tipTarget) { tipShow(el, 'hover'); }
  });
  document.addEventListener('mouseout', function (ev) {
    var el = ev.target.closest('[data-tip]');
    // Solo ciò che il mouse ha aperto si chiude uscendo: un tocco genera
    // comunque mouseover/mouseout, che altrimenti richiuderebbero subito
    if (el && el === tipTarget && tipSource === 'hover' && !el.contains(ev.relatedTarget)) {
      tipHide();
    }
  });
  document.addEventListener('focusin', function (ev) {
    var el = ev.target.closest('[data-tip]');
    // Solo il focus da tastiera: quello che segue un tocco lo gestisce il click
    if (el && el.matches(':focus-visible')) { tipShow(el, 'focus'); }
  });
  document.addEventListener('focusout', function (ev) {
    if (ev.target.closest('[data-tip]') === tipTarget && tipSource === 'focus') { tipHide(); }
  });
  // Tocco: il click apre e richiude, e non deve seguire eventuali link
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-tip]');
    if (el) {
      // Un link o un pulsante con una sua azione deve compierla: il
      // suggerimento lo si è già visto al passaggio del mouse
      if (el.tagName === 'A' || el.type === 'submit') { tipHide(); return; }
      ev.preventDefault();
      // Si richiude solo se era stato aperto da un tocco precedente:
      // quello aperto dal passaggio del mouse resta com'è
      if (el === tipTarget && tipSource === 'tap') { tipHide(); } else { tipShow(el, 'tap'); }
      return;
    }
    tipHide();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { tipHide(); }
  });
  window.addEventListener('scroll', tipHide, true);
  window.addEventListener('resize', tipHide);

  /* ---------- Tabelle su schermo stretto ----------
     Sotto una certa larghezza le righe diventano schede: ogni cella porta
     davanti l'intestazione della sua colonna, letta qui dal <thead> così
     non va ripetuta a mano in ogni pagina (né tradotta due volte). */
  Array.prototype.forEach.call(document.querySelectorAll('.table-wrap table'), function (table) {
    var intestazioni = Array.prototype.map.call(
      table.querySelectorAll('thead th'),
      function (th) { return th.textContent.trim(); }
    );
    if (!intestazioni.length) { return; }
    Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) {
        var etichetta = intestazioni[i];
        if (etichetta) { td.setAttribute('data-label', etichetta); }
      });
    });
  });

  /* ---------- Campo PIN: solo cifre ---------- */
  var pin = document.querySelector('.pin-input');
  if (pin) {
    pin.addEventListener('input', function () {
      pin.value = pin.value.replace(/\D/g, '').slice(0, 6);
    });
    pin.focus();
  }
})();
