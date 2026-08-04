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
      return res.json().then(function (json) {
        if (!res.ok) { throw new Error(json.error || 'Errore di rete'); }
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
  var bandsBox = document.getElementById('bands');
  if (bandsBox) {
    var scheduleForm = document.getElementById('schedule-form');
    var idleModeSel = document.getElementById('idle_mode');
    var idleField = document.getElementById('idle-media-field');

    // Costruzione via DOM: i valori non passano mai da innerHTML
    function addBand(from, to) {
      var row = document.createElement('div');
      row.className = 'band-row';

      var inputFrom = document.createElement('input');
      inputFrom.type = 'time';
      inputFrom.className = 'band-from';
      inputFrom.required = true;
      inputFrom.value = from || '09:00';

      var sep = document.createElement('span');
      sep.className = 'band-sep';
      sep.textContent = '→';

      var inputTo = document.createElement('input');
      inputTo.type = 'time';
      inputTo.className = 'band-to';
      inputTo.required = true;
      inputTo.value = to || '20:00';

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'remove';
      remove.title = 'Rimuovi fascia';
      remove.textContent = '×';
      remove.addEventListener('click', function () { row.remove(); });

      row.append(inputFrom, sep, inputTo, remove);
      bandsBox.appendChild(row);
    }

    (JSON.parse(bandsBox.dataset.bands || '[]') || []).forEach(function (b) {
      addBand(b.from, b.to);
    });

    document.getElementById('band-add').addEventListener('click', function () { addBand(); });

    scheduleForm.addEventListener('submit', function (ev) {
      var bands = [];
      var invalid = null;
      Array.prototype.forEach.call(bandsBox.querySelectorAll('.band-row'), function (row) {
        var from = row.querySelector('.band-from').value;
        var to = row.querySelector('.band-to').value;
        if (!from || !to || from === to) { invalid = invalid || row; return; }
        bands.push({ from: from, to: to });
      });
      // Mai scartare fasce in silenzio: si blocca il salvataggio e si segnala
      if (invalid) {
        ev.preventDefault();
        invalid.querySelector('.band-from').focus();
        toast('Fascia incompleta: inizio e fine devono essere valorizzati e diversi', true);
        return;
      }
      document.getElementById('schedule-json').value = JSON.stringify(bands);
    });

    function syncIdleField() {
      idleField.style.display = idleModeSel.value === 'image' ? '' : 'none';
    }
    idleModeSel.addEventListener('change', syncIdleField);
    syncIdleField();

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
    var caps = form.querySelector('[data-caps]');
    var note = form.querySelector('[data-admin-note]');
    var isAdmin = select.value === 'admin';
    if (caps) { caps.hidden = isAdmin; }
    if (note) { note.hidden = !isAdmin; }
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
