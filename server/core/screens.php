<?php
declare(strict_types=1);

// Logica di pairing e playlist, condivisa tra API (dispositivi) e CMS (admin).

// Transizioni disponibili (whitelist unica per CMS e API).
// 'kenburns' anima anche durante l'esposizione delle immagini;
// 'random' fa scegliere all'app una transizione diversa a ogni cambio.
// Le costanti elencano le transizioni; le etichette leggibili stanno nei
// cataloghi delle traduzioni (transition_labels()).
const TRANSITIONS = [
    'cut'         => 'tr.cut',
    'fade'        => 'tr.fade',
    'crossfade'   => 'tr.crossfade',
    'slide_left'  => 'tr.slide_left',
    'slide_right' => 'tr.slide_right',
    'slide_up'    => 'tr.slide_up',
    'slide_down'  => 'tr.slide_down',
    'zoom_in'     => 'tr.zoom_in',
    'zoom_out'    => 'tr.zoom_out',
    'kenburns'    => 'tr.kenburns',
    'random'      => 'tr.random',
];

// Etichette brevi per i menu stretti delle card media
const TRANSITIONS_SHORT = [
    'cut'         => 'trs.cut',
    'fade'        => 'trs.fade',
    'crossfade'   => 'trs.crossfade',
    'slide_left'  => 'trs.slide_left',
    'slide_right' => 'trs.slide_right',
    'slide_up'    => 'trs.slide_up',
    'slide_down'  => 'trs.slide_down',
    'zoom_in'     => 'trs.zoom_in',
    'zoom_out'    => 'trs.zoom_out',
    'kenburns'    => 'trs.kenburns',
    'random'      => 'trs.random',
];

// Elenchi chiave => etichetta tradotta, nell'ordine di dichiarazione
function transition_labels(): array
{
    return array_map(fn (string $k): string => t($k), TRANSITIONS);
}

function transition_labels_short(): array
{
    return array_map(fn (string $k): string => t($k), TRANSITIONS_SHORT);
}

// `orientation` non è più una scelta indipendente: è la forma che il
// pannello assume agli occhi di chi guarda, e quella dipende SOLO da
// quanto è ruotato rispetto al segnale grezzo (sempre orizzontale). Un
// pannello ruotato di 90° o 270° appare verticale; dritto o capovolto
// (0°/180°) resta orizzontale. Va sempre scritta insieme a `rotation`,
// mai scelta a parte: è così che nasceva il disallineamento che rompeva
// le anteprime (uno schermo "orizzontale" ma ruotato di 90°).
function screen_orientation_for_rotation(int $rotation): string
{
    return ($rotation === 90 || $rotation === 270) ? 'portrait' : 'landscape';
}

// Foto caricate prima dell'introduzione di width/height non le hanno
// ancora in tabella: si calcolano al bisogno, una volta sola, e si
// salvano subito — stessa idea della pulizia opportunistica già usata
// altrove nel progetto (niente migrazione batch a parte).
function media_ensure_dimensions(array $media): array
{
    if ($media['type'] !== 'image' || $media['width'] !== null) {
        return $media;
    }
    $path = UPLOADS_DIR . '/' . $media['filename'];
    $dimensions = is_file($path) ? @getimagesize($path) : false;
    if ($dimensions === false) {
        return $media; // file mancante o illeggibile: resta senza, niente rotazione automatica
    }
    [$width, $height] = $dimensions;
    db()->prepare('UPDATE media SET width = ?, height = ? WHERE id = ?')
        ->execute([$width, $height, (int) $media['id']]);
    $media['width'] = $width;
    $media['height'] = $height;
    return $media;
}

// Rotazione da applicare in riproduzione per QUESTO schermo. L'ordine:
// 1. un override manuale su questo schermo vince sempre;
// 2. altrimenti, per le immagini di cui si conosce l'orientamento, si
//    calcola da sola — vedi il ragionamento sotto;
// 3. altrimenti (video, o immagine di cui non si conosce ancora
//    l'orientamento) nessuna rotazione: 0°.
//
// Il calcolo compone DUE correzioni indipendenti, non una sola:
// - la stessa compensazione già usata per il banner (vedi
//   BannerController.kt sull'app): il buffer grezzo è sempre
//   orizzontale, quindi per apparire dritto sul pannello — quale che
//   sia il suo montaggio — un contenuto va predisegnato ruotato di
//   (360 - screen.rotation) gradi;
// - +90° in più SOLO se l'orientamento nativo della foto (verticale se
//   più alta che larga) non coincide con quello dello schermo (portrait
//   se rotation è 90 o 270): è la parte che allinea il lato lungo della
//   foto al lato lungo dello schermo, come richiesto.
// Se invece l'orientamento già coincide, resta solo la prima
// correzione — serve comunque, altrimenti su schermi ruotati anche le
// foto "già giuste" apparirebbero storte.
function effective_item_rotation(array $media, array $screen, ?int $override): int
{
    if ($override !== null) {
        return $override;
    }
    if ($media['type'] !== 'image' || $media['width'] === null || $media['height'] === null) {
        return 0;
    }
    $screenRotation = (int) $screen['rotation'];
    $photoPortrait = (int) $media['height'] > (int) $media['width'];
    $screenPortrait = screen_orientation_for_rotation($screenRotation) === 'portrait';
    $matchRotation = $photoPortrait === $screenPortrait ? 0 : 90;
    $mountCompensation = (360 - $screenRotation) % 360;
    return ($mountCompensation + $matchRotation) % 360;
}

// Crea uno schermo 'pending' con PIN univoco. Ritenta in caso di
// collisione sul codice (violazione della UNIQUE KEY, SQLSTATE 23000).
function create_pending_screen(?string $deviceInfo): array
{
    $pdo = db();

    // Pulizia dei tentativi di pairing scaduti e mai completati.
    // Gli schermi pre-creati dal CMS (metodo manuale) hanno claim_token
    // e non vanno toccati.
    $pdo->query("DELETE FROM screens
                 WHERE status = 'pending'
                   AND claim_token IS NULL
                   AND pairing_expires_at < NOW()");

    $expiresAt = date('Y-m-d H:i:s', time() + (int) config('pairing_ttl'));
    $pollToken = random_hex();
    // L'auth_token nasce QUI e viaggia verso il dispositivo una sola volta,
    // in questa risposta: nel database entra solo la sua impronta, quindi
    // non potrebbe essere consegnato in un momento successivo. Resta inerte
    // finché l'amministratore non conferma (require_device esige 'paired').
    $authToken = random_hex();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = random_pin();
        try {
            $pdo->prepare('INSERT INTO screens
                           (pairing_code, pairing_expires_at, poll_token, auth_token, device_info)
                           VALUES (?, ?, ?, ?, ?)')
                ->execute([$code, $expiresAt, token_hash($pollToken), token_hash($authToken), $deviceInfo]);

            return [
                'id'           => (int) $pdo->lastInsertId(),
                'pairing_code' => $code,
                'poll_token'   => $pollToken,
                'auth_token'   => $authToken,
            ];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    json_error('Impossibile generare un codice di pairing, riprovare', 500);
}

// Chiamata dal CMS quando l'admin inserisce il PIN (o conferma via QR).
// Genera l'auth_token (il dispositivo lo ritirerà al prossimo polling)
// e restituisce l'id dello schermo, oppure null se il codice non è valido.
function confirm_pairing(string $code, string $method): ?int
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM screens
                           WHERE pairing_code = ?
                             AND status = 'pending'
                             AND pairing_expires_at >= NOW()");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return null;
    }

    // Il dispositivo possiede già il token dall'avvio del pairing: qui si
    // attiva soltanto. pairing_expires_at resta come finestra entro cui la
    // TV deve accorgersi della conferma: se non interroga mai, la riga
    // torna pending e la pulizia la rimuove.
    $stmt = $pdo->prepare("UPDATE screens
                           SET status = 'paired',
                               pair_method = ?,
                               pairing_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                           WHERE id = ? AND status = 'pending'");
    $stmt->execute([$method, (int) config('pairing_ttl'), $id]);

    // Conferme simultanee dello stesso PIN: solo la prima passa
    if ($stmt->rowCount() !== 1) {
        return null;
    }

    screen_event_add((int) $id, 'paired', $method);

    return (int) $id;
}

// Salva un upload del CMS dentro una galleria e registra il media.
// Validazione MIME dal contenuto reale; con $imagesOnly accetta solo immagini.
// Ritorna [id, label] del nuovo media; esce con json_error in caso di problemi.
function store_uploaded_media(array $file, int $galleryId, bool $imagesOnly = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload fallito (file troppo grande per i limiti PHP dell\'hosting?)', 422);
    }
    if ($file['size'] > (int) config('max_upload_bytes')) {
        json_error('File oltre il limite consentito', 422);
    }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
    if (!isset($allowed[$mime]) || ($imagesOnly && !str_starts_with($mime, 'image/'))) {
        json_error('Formato non supportato: ' . $mime, 422);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        json_error('Impossibile salvare il file (permessi cartella uploads?)', 500);
    }

    // Dimensioni naturali: servono a capire da sole se una foto è verticale
    // o orizzontale, per la rotazione automatica per schermo (vedi
    // effective_item_rotation()). Solo immagini: per i video servirebbe
    // ffprobe, non garantito su ogni hosting — restano senza, rotazione
    // manuale come prima.
    $width = null;
    $height = null;
    if (str_starts_with($mime, 'image/')) {
        $dimensions = @getimagesize(UPLOADS_DIR . '/' . $filename);
        if ($dimensions !== false) {
            [$width, $height] = $dimensions;
        }
    }

    $pdo = db();
    $originalName = mb_substr((string) $file['name'], 0, 255);

    // sort_order calcolato dentro la INSERT: upload concorrenti nella stessa
    // galleria non possono ottenere la stessa posizione
    $pdo->prepare('INSERT INTO media
                   (gallery_id, type, filename, original_name, mime_type, filesize, checksum, width, height, sort_order)
                   SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(m.sort_order), -1) + 1
                   FROM media m WHERE m.gallery_id = ? AND m.deleted_at IS NULL')
        ->execute([
            $galleryId,
            str_starts_with($mime, 'image/') ? 'image' : 'video',
            $filename,
            $originalName,
            $mime,
            $file['size'],
            sha1_file(UPLOADS_DIR . '/' . $filename),
            $width,
            $height,
            $galleryId,
        ]);
    $newId = (int) $pdo->lastInsertId(); // prima di altri statement

    $pdo->prepare('UPDATE galleries SET updated_at = NOW() WHERE id = ?')->execute([$galleryId]);

    // Copia ridotta per la riproduzione, subito: è il momento giusto,
    // perché l'amministratore sta già aspettando l'esito di un upload e
    // nessuno schermo sta aspettando questa richiesta. Se fallisce, il
    // media resta perfettamente utilizzabile con il solo originale.
    media_ensure_rendition([
        'id'           => $newId,
        'type'         => str_starts_with($mime, 'image/') ? 'image' : 'video',
        'filename'     => $filename,
        'mime_type'    => $mime,
        'rendition_at' => null,
    ]);

    return ['id' => $newId, 'label' => $originalName];
}

// Galleria di servizio. Identificata dal system_key (non dal nome, che
// l'utente potrebbe cambiare) e protetta da rinomina/eliminazione nel CMS.
function courtesy_gallery_id(): int
{
    $pdo = db();
    $id = $pdo->query("SELECT id FROM galleries WHERE system_key = 'courtesy' AND deleted_at IS NULL LIMIT 1")
        ->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare("INSERT INTO galleries (name, system_key) VALUES (?, 'courtesy')")
        ->execute([t('sys.gallery_name')]);
    $id = (int) $pdo->lastInsertId();
    restore_system_media($id);
    return $id;
}

// Copia in galleria i file di servizio che mancano. Ritorna quanti ne ha
// aggiunti: chiamarla quando ci sono già tutti non fa nulla.
function restore_system_media(?int $galleryId = null): int
{
    $galleryId ??= courtesy_gallery_id();
    $pdo = db();
    $sorgente = system_media_dir();
    $aggiunti = 0;

    foreach (SYSTEM_MEDIA as $file => $chiave) {
        $origine = $sorgente . '/' . $file;
        if (!is_file($origine)) {
            continue;
        }
        $checksum = sha1_file($origine);

        // Già presente in galleria (stesso contenuto): non si duplica
        $stmt = $pdo->prepare('SELECT id FROM media
                               WHERE gallery_id = ? AND checksum = ? AND deleted_at IS NULL');
        $stmt->execute([$galleryId, $checksum]);
        if ($stmt->fetchColumn() !== false) {
            continue;
        }

        $nome = bin2hex(random_bytes(16)) . '.png';
        if (!@copy($origine, UPLOADS_DIR . '/' . $nome)) {
            continue; // cartella non scrivibile: si riproverà più avanti
        }

        $pdo->prepare('INSERT INTO media
                       (gallery_id, type, filename, original_name, mime_type,
                        filesize, checksum, sort_order)
                       SELECT ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(m.sort_order), -1) + 1
                       FROM media m WHERE m.gallery_id = ? AND m.deleted_at IS NULL')
            ->execute([
                $galleryId, 'image', $nome, t($chiave), 'image/png',
                filesize($origine), $checksum, $galleryId,
            ]);
        $aggiunti++;
    }

    if ($aggiunti > 0) {
        $pdo->prepare('UPDATE galleries SET updated_at = NOW() WHERE id = ?')->execute([$galleryId]);
    }
    return $aggiunti;
}

// Playlist di una singola galleria, nello stesso formato di quella degli
// schermi: serve all'anteprima nel CMS (nessuna fascia oraria, nessun idle).
function playlist_for_gallery(array $gallery): array
{
    $stmt = db()->prepare('SELECT m.*,
                                  COALESCE(m.transition_in,  :t1) AS trans_in,
                                  COALESCE(m.transition_out, :t2) AS trans_out
                           FROM media m
                           WHERE m.gallery_id = :gid AND m.deleted_at IS NULL
                           ORDER BY m.sort_order, m.id');
    $stmt->execute([
        't1'  => $gallery['transition_default'],
        't2'  => $gallery['transition_default'],
        'gid' => $gallery['id'],
    ]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        // Questa playlist alimenta anche le dirette su galleria, che vanno
        // ai dispositivi: la copia ridotta serve qui come nella playlist
        // dello schermo. L'anteprima del CMS non ne risente, perché
        // riscrive comunque le URL verso media_preview.php (originale).
        $row = media_ensure_rendition($row);
        $delivery = media_delivery($row);
        $items[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type'],
            'mime'           => $delivery['mime'],
            'url'            => config('base_url') . '/api/v1/media.php?id=' . $row['id']
                                . '&v=' . $delivery['checksum'],
            'filesize'       => $delivery['filesize'],
            'checksum'       => $delivery['checksum'],
            // Nessuna rotazione qui: la rotazione si calcola confrontando
            // l'orientamento della foto con quello di UNO schermo preciso,
            // e questa è l'anteprima "vergine" di una galleria non ancora
            // assegnata — l'anteprima per schermo (playlist_for_screen)
            // mostra invece il calcolo vero
            'rotation'       => 0,
            'fit'            => $row['fit'],
            'duration'       => (int) $row['duration_seconds'],
            'transition_in'  => $row['trans_in'],
            'transition_out' => $row['trans_out'],
            'transition_ms'  => (int) $gallery['transition_duration_ms'],
            'muted'          => (bool) $row['muted'],
            'name'           => $row['original_name'],
        ];
    }

    return [
        'screen'        => ['id' => 0, 'name' => $gallery['name'], 'orientation' => 'landscape', 'inches' => null],
        'schedule'      => ['bands' => [], 'idle_mode' => 'black', 'idle_item' => null],
        'version'       => sha1(json_encode($items)),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}

// Anteprima di una galleria COME APPARIREBBE su uno schermo REALE, che sia
// o no già fra quelli a cui è assegnata — serve a scegliere/verificare la
// rotazione prima ancora di assegnarla, invece di indovinare. A differenza
// di playlist_for_gallery() (nessuno schermo vero, sempre orizzontale,
// rotazione sempre 0) qui la rotazione è quella VERA che si otterrebbe:
// stesso calcolo di playlist_for_screen(), stesso banner dello schermo.
function playlist_for_gallery_on_screen(array $gallery, array $screen): array
{
    $stmt = db()->prepare('SELECT m.id, m.type, m.filename, m.mime_type, m.filesize, m.checksum,
                                  m.width, m.height, m.fit, m.duration_seconds, m.muted, m.original_name,
                                  smo.rotation AS override_rotation,
                                  COALESCE(m.transition_in,  :t1) AS transition_in,
                                  COALESCE(m.transition_out, :t2) AS transition_out
                           FROM media m
                           LEFT JOIN screen_media_overrides smo
                                  ON smo.screen_id = :sid AND smo.media_id = m.id
                           WHERE m.gallery_id = :gid AND m.deleted_at IS NULL
                           ORDER BY m.sort_order, m.id');
    $stmt->execute([
        't1'  => $gallery['transition_default'],
        't2'  => $gallery['transition_default'],
        'sid' => $screen['id'],
        'gid' => $gallery['id'],
    ]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $row = media_ensure_dimensions($row);
        $override = $row['override_rotation'] !== null ? (int) $row['override_rotation'] : null;
        $items[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type'],
            'mime'           => $row['mime_type'],
            'url'            => config('base_url') . '/api/v1/media.php?id=' . $row['id'],
            'filesize'       => (int) $row['filesize'],
            'checksum'       => $row['checksum'],
            'rotation'       => effective_item_rotation($row, $screen, $override),
            'fit'            => $row['fit'],
            'duration'       => (int) $row['duration_seconds'],
            'transition_in'  => $row['transition_in'],
            'transition_out' => $row['transition_out'],
            'transition_ms'  => (int) $gallery['transition_duration_ms'],
            'muted'          => (bool) $row['muted'],
            'name'           => $row['original_name'],
        ];
    }

    return [
        'screen' => [
            'id'          => (int) $screen['id'],
            'name'        => $screen['name'],
            'orientation' => $screen['orientation'],
            'rotation'    => (int) $screen['rotation'],
            'inches'      => $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : null,
        ],
        'schedule'      => ['bands' => [], 'idle_mode' => 'black', 'idle_item' => null],
        'banner'        => function_exists('overlay_payload_for_screen') ? overlay_payload_for_screen($screen) : null,
        'version'       => sha1(json_encode($items)),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}

// Impronta della configurazione che il player deve rispettare: media,
// fasce orarie e contenuto di riposo. Calcolata senza costruire l'intera
// playlist, così l'endpoint di stato resta leggero.
function playlist_version(array $screen): string
{
    // width/height e l'eventuale override sostituiscono m.rotation qui:
    // è da questi due che si ricalcola la rotazione effettiva, quindi un
    // cambiamento di uno dei due (nuova analisi della foto, override
    // impostato o tolto) deve far scattare un nuovo download tanto quanto
    // prima faceva un cambio diretto della rotazione.
    // Il checksum della copia ridotta entra nella firma accanto a quello
    // dell'originale: è il file che la TV scarica davvero, quindi quando
    // compare (o cambia) la playlist deve risultare nuova subito, senza
    // aspettare il giro di polling successivo. Assente finché la
    // migrazione della 1.13.0 non è stata eseguita.
    $rendition = rendition_columns_available() ? ", COALESCE(m.rendition_checksum, '')" : '';
    $stmt = db()->prepare("SELECT GROUP_CONCAT(CONCAT_WS(':',
                                  m.id, m.checksum$rendition, m.width, m.height,
                                  COALESCE(smo.rotation, -1), m.fit, m.duration_seconds,
                                  m.muted, COALESCE(m.transition_in, g.transition_default),
                                  COALESCE(m.transition_out, g.transition_default),
                                  g.transition_duration_ms)
                                  ORDER BY sg.sort_order, m.gallery_id, m.sort_order, m.id) AS sig
                           FROM media m
                           JOIN screen_galleries sg ON sg.gallery_id = m.gallery_id
                           JOIN galleries g        ON g.id = m.gallery_id
                           LEFT JOIN screen_media_overrides smo
                                  ON smo.screen_id = sg.screen_id AND smo.media_id = m.id
                           WHERE sg.screen_id = ?
                             AND m.deleted_at IS NULL AND g.deleted_at IS NULL");
    $stmt->execute([$screen['id']]);

    return sha1(implode('|', [
        (string) $stmt->fetchColumn(),
        (string) $screen['schedule_json'],
        (string) $screen['idle_mode'],
        (string) $screen['idle_media_id'],
        // Il montaggio dello schermo entra qui: cambiandolo cambia la
        // rotazione calcolata per OGNI foto, anche senza toccarne nessuna
        (string) $screen['rotation'],
        // Anche il banner fa parte di ciò che la TV deve rispettare
        json_encode(function_exists('overlay_payload_for_screen')
            ? overlay_payload_for_screen($screen)
            : null),
        // …e la lingua: cambiandola gli schermi devono riscaricare subito
        (string) (setting_get('default_language') ?: LANG_FALLBACK),
        // …e la scelta sul colore: senza, cambiandola dal pannello le TV
        // continuerebbero a riprodurre come prima fino al riavvio
        tv_low_color() ? '1' : '0',
    ]));
}

// Playlist di uno schermo: gallerie assegnate in ordine, media in sort_order.
// 'version' cambia se cambia qualunque cosa rilevante per il player:
// l'app la confronta con quella in cache per decidere se aggiornare.
function playlist_for_screen(array $screen): array
{
    // COALESCE: il media eredita la transizione della galleria salvo override.
    // smo.rotation: eventuale correzione manuale SOLO per questo schermo.
    $stmt = db()->prepare('SELECT m.id, m.type, m.filename, m.mime_type, m.filesize, m.checksum,
                                  m.width, m.height, m.fit, m.duration_seconds, m.muted,
                                  smo.rotation AS override_rotation,
                                  COALESCE(m.transition_in,  g.transition_default) AS transition_in,
                                  COALESCE(m.transition_out, g.transition_default) AS transition_out,
                                  g.transition_duration_ms' . rendition_select_fields('m') . '
                           FROM media m
                           JOIN screen_galleries sg ON sg.gallery_id = m.gallery_id
                           JOIN galleries g        ON g.id = m.gallery_id
                           LEFT JOIN screen_media_overrides smo
                                  ON smo.screen_id = sg.screen_id AND smo.media_id = m.id
                           WHERE sg.screen_id = ?
                             AND m.deleted_at IS NULL AND g.deleted_at IS NULL
                           ORDER BY sg.sort_order, m.gallery_id, m.sort_order, m.id');
    $stmt->execute([$screen['id']]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $row = media_ensure_dimensions($row);
        // Copia ridotta: generata all'upload dalla 1.13.0 in poi, qui si
        // recuperano i file caricati prima (due per richiesta, vedi
        // RENDITION_MAX_PER_REQUEST)
        $row = media_ensure_rendition($row);
        $delivery = media_delivery($row);
        $override = $row['override_rotation'] !== null ? (int) $row['override_rotation'] : null;
        $items[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type'],
            // MIME e checksum sono quelli del file CONSEGNATO, non
            // dell'originale: l'app usa il checksum per validare il
            // download e come nome in cache, e il MIME per l'estensione
            'mime'           => $delivery['mime'],
            'url'            => config('base_url') . '/api/v1/media.php?id=' . $row['id']
                                . '&v=' . $delivery['checksum'],
            'filesize'       => $delivery['filesize'],
            'checksum'       => $delivery['checksum'],
            'rotation'       => effective_item_rotation($row, $screen, $override),
            'fit'            => $row['fit'],
            'duration'       => (int) $row['duration_seconds'],
            'transition_in'  => $row['transition_in'],
            'transition_out' => $row['transition_out'],
            'transition_ms'  => (int) $row['transition_duration_ms'],
            'muted'          => (bool) $row['muted'],
        ];
    }

    // Fasce orarie con i giorni: la TV le valuta in locale, quindi la
    // programmazione continua a valere anche a rete assente.
    // Nessuna fascia = sempre attivo. Fuori fascia: nero o immagine di cortesia.
    $bands = schedule_parse($screen['schedule_json']);

    $idleItem = null;
    if ($screen['idle_mode'] === 'image' && $screen['idle_media_id'] !== null) {
        $stmt = db()->prepare("SELECT m.*, smo.rotation AS override_rotation
                               FROM media m
                               LEFT JOIN screen_media_overrides smo
                                      ON smo.screen_id = ? AND smo.media_id = m.id
                               WHERE m.id = ? AND m.type = 'image' AND m.deleted_at IS NULL");
        $stmt->execute([$screen['id'], $screen['idle_media_id']]);
        if (($m = $stmt->fetch()) !== false) {
            $m = media_ensure_dimensions($m);
            $m = media_ensure_rendition($m);
            $delivery = media_delivery($m);
            $override = $m['override_rotation'] !== null ? (int) $m['override_rotation'] : null;
            $idleItem = [
                'id'       => (int) $m['id'],
                'type'     => 'image',
                'mime'     => $delivery['mime'],
                'url'      => config('base_url') . '/api/v1/media.php?id=' . $m['id']
                              . '&idle=1&v=' . $delivery['checksum'],
                'filesize' => $delivery['filesize'],
                'checksum' => $delivery['checksum'],
                'rotation' => effective_item_rotation($m, $screen, $override),
                'fit'      => $m['fit'],
            ];
        }
    }

    return [
        'screen' => [
            'id'          => (int) $screen['id'],
            'name'        => $screen['name'],
            'orientation' => $screen['orientation'],
            'rotation'    => (int) $screen['rotation'],
            'inches'      => $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : null,
        ],
        'schedule' => [
            'bands'     => $bands,
            'idle_mode' => $screen['idle_mode'],
            'idle_item' => $idleItem,
        ],
        'branding' => [
            'title'    => branding_tv_title(),
            'subtitle' => branding('brand_tv_subtitle'),
            'accent'   => branding_accent(),
            // Lingua degli schermi: quella predefinita dell'installazione,
            // NON quella dell'operatore. Chi lavora nel pannello in inglese
            // non deve cambiare la lingua mostrata al pubblico.
            'language' => (string) (setting_get('default_language') ?: LANG_FALLBACK),
        ],
        // Banner in sovraimpressione di QUESTO schermo: può essere suo
        // oppure ereditato dalle impostazioni generali. null quando non c'è
        // nulla da mostrare, così la TV lo smonta senza casi particolari
        'banner' => function_exists('overlay_payload_for_screen')
            ? overlay_payload_for_screen($screen)
            : null,
        // Diretta in sovrapposizione attiva su questo schermo, se c'è:
        // la playlist sottostante resta invariata e riprende alla fine
        'override' => function_exists('override_payload')
            ? override_payload(active_override_for_screen((int) $screen['id']))
            : null,
        // Come la TV deve riprodurre, al di là di cosa riprodurre.
        // low_color: permesso di dimezzare la memoria delle foto a scapito
        // delle sfumature (vedi tv_low_color e PlayerController)
        'playback' => [
            'low_color' => tv_low_color(),
        ],
        // Stessa identica impronta restituita da state.php: è il confronto
        // che permette alla TV di accorgersi di una modifica entro pochi
        // secondi. Due formule diverse non sarebbero mai confrontabili.
        'version'       => playlist_version($screen),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}
