<?php
declare(strict_types=1);

/*
 * Frame Paring Store — sistema di digital signage self-hosted
 * Copyright (C) 2026 Davide Pica
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA. Vedi la GNU Affero General Public License per i dettagli.
 * Dovresti aver ricevuto una copia della licenza insieme a questo programma;
 * in caso contrario: https://www.gnu.org/licenses/
 */

// Dirette in sovrapposizione: un livello temporaneo sopra la riproduzione
// normale. Le gallerie, le fasce orarie e le impostazioni degli schermi non
// vengono mai toccate: alla fine della diretta tutto riprende da solo.

// Diretta attiva su uno schermo, se c'è. Una diretta è attiva quando non è
// stata interrotta e non è scaduta.
function active_override_for_screen(int $screenId): ?array
{
    // COALESCE: lo schermo usa i propri valori se li ha, altrimenti quelli
    // della diretta. Così un pannello verticale può ruotare o ritagliare
    // diversamente dagli altri senza far partire una seconda diretta.
    $stmt = db()->prepare('SELECT o.*,
                                  COALESCE(s.fit, o.fit) AS fit,
                                  COALESCE(s.rotation, o.rotation) AS rotation
                           FROM live_overrides o
                           JOIN live_override_screens s ON s.override_id = o.id
                           WHERE s.screen_id = ?
                             AND o.stopped_at IS NULL
                             AND (o.ends_at IS NULL OR o.ends_at > NOW())
                             AND o.started_at <= NOW()
                           ORDER BY o.started_at DESC
                           LIMIT 1');
    $stmt->execute([$screenId]);
    return $stmt->fetch() ?: null;
}

// Rappresentazione per l'app. Include l'istante di avvio in modo che tutti
// gli schermi calcolino la stessa posizione nel file e partano allineati.
//
// $light: variante per state.php, interrogato ogni pochi secondi. Con una
// galleria da decine di file, spedirne l'elenco a ogni battito peserebbe
// inutilmente: si manda solo l'impronta, e l'app scarica l'elenco intero
// dalla playlist quando l'impronta cambia.
function override_payload(?array $override, bool $light = false): ?array
{
    if ($override === null) {
        return null;
    }

    $payload = [
        'id'         => (int) $override['id'],
        'name'       => $override['name'],
        'type'       => $override['source_type'],
        'fit'        => $override['fit'],
        'rotation'   => (int) $override['rotation'],
        'muted'      => (bool) $override['muted'],
        'loop'       => (bool) $override['loop_content'],
        // secondi trascorsi dall'avvio: la TV ci si posiziona dentro
        'elapsed'    => max(0, time() - strtotime($override['started_at'])),
        'ends_in'    => $override['ends_at'] !== null
            ? max(0, strtotime($override['ends_at']) - time())
            : null,
    ];

    if ($override['source_type'] === 'gallery') {
        // Una galleria intera: si mandano gli item con i loro metadati, nella
        // stessa forma della playlist, così la TV riusa il proprio player
        // invece di un percorso a parte.
        $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$override['gallery_id']]);
        $gallery = $stmt->fetch();
        if ($gallery === false) {
            return null; // galleria eliminata: la diretta decade
        }
        $items = playlist_for_gallery($gallery)['items'];
        if ($items === []) {
            return null; // galleria svuotata: non c'è nulla da mandare in onda
        }
        // Le URL portano live=1: autorizzano il download anche quando la
        // galleria non è assegnata a quello schermo
        foreach ($items as &$item) {
            $item['url'] .= '&live=1';
        }
        unset($item);
        // L'impronta cambia se cambia qualsiasi cosa dei file: è così che
        // una galleria modificata A DIRETTA IN CORSO arriva agli schermi
        $payload += [
            'gallery_name' => $gallery['name'],
            'items_sig'    => sha1(json_encode($items)),
        ];
        if (!$light) {
            $payload += ['items' => $items];
        }
        return $payload;
    }

    if ($override['source_type'] === 'media') {
        $stmt = db()->prepare('SELECT m.id, m.type, m.filename, m.mime_type, m.filesize, m.checksum'
                              . rendition_select_fields('m') . '
                               FROM media m WHERE m.id = ? AND m.deleted_at IS NULL');
        $stmt->execute([$override['media_id']]);
        $media = $stmt->fetch();
        if ($media === false) {
            return null; // il file è stato eliminato: la diretta decade
        }
        // Anche una diretta su singolo file va consegnata nella copia
        // ridotta: è lo stesso dispositivo, con la stessa memoria
        $media = media_ensure_rendition($media);
        $delivery = media_delivery($media);
        $payload += [
            'media_type' => $media['type'],
            'mime'       => $delivery['mime'],
            'url'        => config('base_url') . '/api/v1/media.php?id=' . $media['id']
                            . '&live=1&v=' . $delivery['checksum'],
            'filesize'   => $delivery['filesize'],
            'checksum'   => $delivery['checksum'],
        ];
    } else {
        $payload += [
            'media_type' => 'video',
            'mime'       => 'application/x-mpegURL',
            'url'        => $override['source_url'],
            'checksum'   => null, // flusso: non si scarica in cache
        ];
    }

    return $payload;
}

// Chiude le dirette scadute: mantiene puliti gli elenchi del CMS
function close_expired_overrides(): void
{
    db()->query('UPDATE live_overrides
                 SET stopped_at = ends_at
                 WHERE stopped_at IS NULL AND ends_at IS NOT NULL AND ends_at <= NOW()');
}

// Gallerie utilizzabili in diretta: quelle non nel cestino e non vuote
function live_galleries(): array
{
    return db()->query("SELECT g.id, g.name, COUNT(m.id) AS n_media
                        FROM galleries g
                        JOIN media m ON m.gallery_id = g.id AND m.deleted_at IS NULL
                        WHERE g.deleted_at IS NULL
                        GROUP BY g.id
                        HAVING n_media > 0
                        ORDER BY g.name")->fetchAll();
}

// Avvia una diretta sugli schermi indicati. Ritorna l'id creato.
// Le dirette precedenti sugli stessi schermi vengono chiuse: uno schermo
// non può essere sotto due sovrapposizioni contemporaneamente.
// $screens: mappa id => ['fit' => ?string, 'rotation' => ?int]. I valori
// nulli significano "come la diretta".
function start_override(array $data, array $screens, int $userId): int
{
    $pdo = db();
    $pdo->beginTransaction();

    $screenIds = array_keys($screens);
    if ($screenIds) {
        $in = implode(',', array_fill(0, count($screenIds), '?'));
        $stmt = $pdo->prepare("UPDATE live_overrides o
                               JOIN live_override_screens s ON s.override_id = o.id
                               SET o.stopped_at = NOW()
                               WHERE o.stopped_at IS NULL AND s.screen_id IN ($in)");
        $stmt->execute($screenIds);
    }

    $pdo->prepare('INSERT INTO live_overrides
                   (name, source_type, media_id, gallery_id, source_url, fit, rotation, muted,
                    loop_content, started_at, ends_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)')
        ->execute([
            $data['name'],
            $data['source_type'],
            $data['media_id'],
            $data['gallery_id'] ?? null,
            $data['source_url'],
            $data['fit'],
            $data['rotation'],
            $data['muted'],
            $data['loop_content'],
            $data['ends_at'],
            $userId,
        ]);
    $id = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO live_override_screens
                           (override_id, screen_id, fit, rotation) VALUES (?, ?, ?, ?)');
    foreach ($screens as $screenId => $opzioni) {
        $stmt->execute([
            $id,
            (int) $screenId,
            $opzioni['fit'] ?? null,
            $opzioni['rotation'] ?? null,
        ]);
    }

    $pdo->commit();
    return $id;
}

function stop_override(int $id): void
{
    db()->prepare('UPDATE live_overrides SET stopped_at = NOW()
                   WHERE id = ? AND stopped_at IS NULL')->execute([$id]);
}

// Da quanti minuti una diretta appena conclusa resta segnalata nel menu
const LIVE_RECENT_MINUTES = 15;

// Stato complessivo delle dirette, per la spia nel menu. Segue la
// convenzione dei pannelli di controllo: verde in onda, ambra appena
// conclusa, rosso qualcosa non va, grigio nulla in corso.
//
// Uno schermo è considerato raggiunto se ha interrogato il server da meno
// di tre cicli: oltre quella soglia la diretta è partita solo sulla carta.
function live_status(): array
{
    // Senza questa chiusura una diretta scaduta da sola resterebbe grigia
    // invece di segnalarsi come appena conclusa
    close_expired_overrides();

    $threshold = max(60, 3 * (int) config('state_interval'));

    $row = db()->prepare("SELECT COUNT(*) AS total,
                                 SUM(CASE WHEN s.status = 'paired'
                                           AND s.last_seen_at IS NOT NULL
                                           AND s.last_seen_at >= (NOW() - INTERVAL ? SECOND)
                                          THEN 1 ELSE 0 END) AS reached
                          FROM live_overrides o
                          JOIN live_override_screens os ON os.override_id = o.id
                          JOIN screens s ON s.id = os.screen_id
                          WHERE o.stopped_at IS NULL
                            AND (o.ends_at IS NULL OR o.ends_at > NOW())
                            AND o.started_at <= NOW()");
    $row->execute([$threshold]);
    $live = $row->fetch();

    $total   = (int) ($live['total'] ?? 0);
    $reached = (int) ($live['reached'] ?? 0);

    if ($total > 0) {
        if ($reached === 0) {
            return ['state' => 'error', 'label' => 'Diretta avviata ma nessuno schermo la sta ricevendo'];
        }
        if ($reached < $total) {
            return [
                'state' => 'error',
                'label' => sprintf('Diretta in onda su %d schermi su %d: gli altri non rispondono',
                    $reached, $total),
            ];
        }
        return [
            'state' => 'live',
            'label' => sprintf('Diretta in onda su %d schermo%s', $total, $total === 1 ? '' : 'i'),
        ];
    }

    $recent = db()->prepare('SELECT name, stopped_at FROM live_overrides
                             WHERE stopped_at IS NOT NULL
                               AND stopped_at >= (NOW() - INTERVAL ? MINUTE)
                             ORDER BY stopped_at DESC LIMIT 1');
    $recent->execute([LIVE_RECENT_MINUTES]);
    $last = $recent->fetch();

    if ($last !== false) {
        return [
            'state' => 'recent',
            'label' => 'Diretta «' . $last['name'] . '» terminata alle '
                . date('H:i', strtotime($last['stopped_at'])),
        ];
    }

    return ['state' => 'idle', 'label' => 'Nessuna diretta in corso'];
}
