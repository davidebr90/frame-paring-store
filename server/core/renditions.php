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

// COPIA PER LA RIPRODUZIONE ("rendition")
//
// Una fotocamera di oggi produce file da 12-20 MB e 30 megapixel. Un
// pannello ne mostra 2. La differenza non è spreco di banda soltanto: è
// la causa diretta dei kill di sistema osservati sul Fire Stick di prima
// generazione, perché una foto entra in memoria DECOMPRESSA — 4 byte per
// pixel — e 31 megapixel fanno 124 MB, su un dispositivo che concede una
// trentina di MB all'app in primo piano. Nessuna astuzia lato app può
// recuperare quel divario: va tolto alla fonte.
//
// Qui si genera, una volta sola per file, una copia ridotta al lato lungo
// di un pannello full HD. È QUELLA che l'app scarica e decodifica;
// l'originale resta intatto su disco e continua a servire il CMS
// (anteprime, download, riuso futuro con schermi più grandi).
//
// GEOMETRIA IDENTICA, DI PROPOSITO: la copia è solo scalata, mai
// riorientata. Applicare l'orientamento EXIF qui cambierebbe come appare
// una foto già in vetrina e falserebbe le rotazioni manuali che
// l'amministratore ha eventualmente già impostato per compensarla a mano
// (vedi effective_item_rotation e screen_media_overrides). La riduzione
// di peso non deve spostare un solo pixel di ciò che si vede.

// Lato lungo della copia: un pannello full HD, anche montato in verticale,
// non mostra più di 1920 pixel sul proprio lato maggiore.
const RENDITION_MAX_SIDE = 1920;

// 82: la soglia oltre la quale, su una foto, si aggiungono byte senza che
// si veda differenza a distanza di visione da vetrina.
const RENDITION_JPEG_QUALITY = 82;

// Sotto questo peso l'originale è già adattissimo: una copia porterebbe
// solo un file in più da gestire. Vale anche per le immagini di sistema.
const RENDITION_MIN_SOURCE_BYTES = 1_200_000;

// Quando la copia ha la STESSA geometria dell'originale (immagine già
// entro il lato lungo, ma salvata pesante) l'unico guadagno possibile è
// la ricompressione: sotto un quinto del peso non vale il file in più.
const RENDITION_MIN_GAIN = 0.20;

// Quando invece la copia rimpicciolisce davvero l'immagine, il metro non
// può essere il peso del file. Una grafica piatta da 3000×2000 può pesare
// 44 KB su disco e ventiquattro MEGABYTE una volta decompressa sul
// dispositivo — che è precisamente il guasto che tutto questo file esiste
// per evitare. Ridotta a 1920 pixel di lato può perfino pesare qualche KB
// in più (il ridimensionamento sfuma i bordi e rende il PNG meno
// comprimibile) e resta comunque la scelta giusta. Si accetta quindi un
// margine in più sul peso, purché piccolo in assoluto: irrilevante per la
// banda, decisivo per la memoria.
const RENDITION_SCALED_BYTES_SLACK = 262_144; // 256 KB

// I formati che GD sa rileggere e riscrivere in questo progetto. Il resto
// (oggi nulla, domani eventuali formati nuovi) passa senza copia.
const RENDITION_SOURCE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

// Quante copie si accetta di generare dentro UNA richiesta. Le copie si
// fanno all'upload; questo tetto vale per il recupero dei file caricati
// prima della 1.13.0, che avviene mentre una TV sta chiedendo la propria
// playlist. Una galleria di venti foto pesanti tutte da recuperare
// insieme supererebbe il tempo massimo di esecuzione dell'hosting e
// lascerebbe lo schermo senza risposta: meglio due per volta, e nel giro
// di qualche polling sono pronte tutte senza che nessuno se ne accorga.
const RENDITION_MAX_PER_REQUEST = 2;

// GD è praticamente ovunque, ma "praticamente" non è "sempre": senza, il
// sistema continua a funzionare esattamente come prima della 1.13.0.
function rendition_available(): bool
{
    return extension_loaded('gd')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled');
}

// Le colonne della copia esistono già nel database? Chi aggiorna carica i
// file del server prima di eseguire la migrazione SQL, e in quella
// finestra una query che le nominasse farebbe fallire la playlist —
// cioè fermerebbe gli schermi. Finché non ci sono, il sistema serve gli
// originali e si comporta esattamente come la 1.12.11.
function rendition_columns_available(): bool
{
    static $disponibili = null;
    if ($disponibili !== null) {
        return $disponibili;
    }
    try {
        db()->query('SELECT rendition_at FROM media LIMIT 0');
        $disponibili = true;
    } catch (Throwable $e) {
        $disponibili = false;
    }
    return $disponibili;
}

// Pezzo di SELECT da aggiungere alle query che elencano i media per un
// dispositivo. Vuoto finché la migrazione non è stata eseguita.
function rendition_select_fields(string $alias = 'm'): string
{
    if (!rendition_columns_available()) {
        return '';
    }
    return ", $alias.rendition_filename, $alias.rendition_filesize,"
        . " $alias.rendition_checksum, $alias.rendition_at";
}

// Nome del file della copia, accanto all'originale in uploads/.
// Il suffisso porta il lato lungo: cambiarlo un domani non farebbe
// collidere le copie nuove con quelle vecchie.
function rendition_filename_for(string $originalFilename, string $ext): string
{
    $base = pathinfo($originalFilename, PATHINFO_FILENAME);
    return $base . '-r' . RENDITION_MAX_SIDE . '.' . $ext;
}

// Quanta memoria PHP resta, in byte. Decomprimere una foto molto grande
// ne chiede 4 byte per pixel: meglio rinunciare alla copia che far morire
// la richiesta con un fatal error, che porterebbe giù anche la playlist.
function rendition_memory_budget(): int
{
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($limit, -1));
    $value = (int) $limit;
    $bytes = match ($unit) {
        'g'     => $value * 1024 * 1024 * 1024,
        'm'     => $value * 1024 * 1024,
        'k'     => $value * 1024,
        default => $value,
    };
    return max(0, $bytes - memory_get_usage(true));
}

// Genera la copia ridotta. Funzione pura: legge un file, ne scrive un
// altro, non tocca il database — così è verificabile da sola.
// Ritorna ['filename' => .., 'filesize' => .., 'checksum' => .., 'width' => ..,
// 'height' => ..] oppure null se la copia non serve o non è possibile.
function rendition_build(string $srcPath, string $mime, string $destDir, string $destName): ?array
{
    if (!rendition_available() || !is_file($srcPath)) {
        return null;
    }
    if (!in_array($mime, RENDITION_SOURCE_MIME, true)) {
        return null;
    }

    $info = @getimagesize($srcPath);
    if ($info === false) {
        return null;
    }
    [$width, $height] = $info;
    if ($width < 1 || $height < 1) {
        return null;
    }

    // Già piccola: ingrandirla sarebbe un danno, ricomprimerla un costo
    // senza guadagno
    $longSide = max($width, $height);
    if ($longSide <= RENDITION_MAX_SIDE && filesize($srcPath) < RENDITION_MIN_SOURCE_BYTES) {
        return null;
    }

    // Sorgente + destinazione decompresse devono entrarci comodamente:
    // 4 byte per pixel, più un margine per il resto della richiesta
    $needed = ($width * $height * 4) + (RENDITION_MAX_SIDE * RENDITION_MAX_SIDE * 4);
    if ($needed > rendition_memory_budget() * 0.7) {
        error_log("Frame Paring: copia di riproduzione saltata per memoria insufficiente ($srcPath)");
        return null;
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($srcPath),
        'image/png'  => @imagecreatefrompng($srcPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        'image/gif'  => @imagecreatefromgif($srcPath),
        default      => false,
    };
    if (!$src) {
        return null;
    }

    try {
        // Scala mantenendo le proporzioni; mai oltre l'originale
        $scale = min(1.0, RENDITION_MAX_SIDE / $longSide);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if (!$dst) {
            return null;
        }

        // JPEG non ha trasparenza: tutto il resto la può avere, e
        // perderla trasformerebbe uno sfondo trasparente in nero
        $keepAlpha = $mime !== 'image/jpeg';
        if ($keepAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // JPEG per le foto (il formato che le comprime davvero), PNG per
        // tutto ciò che può avere trasparenza
        $ext = $keepAlpha ? 'png' : 'jpg';
        $outName = rendition_filename_for($destName, $ext);
        $outPath = rtrim($destDir, '/\\') . '/' . $outName;

        // File temporaneo e rinomina: in uploads/ non compare mai una
        // copia a metà, che verrebbe servita come se fosse buona
        $tmpPath = $outPath . '.part';
        $ok = $keepAlpha
            ? @imagepng($dst, $tmpPath, 6)
            : @imagejpeg($dst, $tmpPath, RENDITION_JPEG_QUALITY);
        imagedestroy($dst);

        if (!$ok || !is_file($tmpPath)) {
            @unlink($tmpPath);
            return null;
        }

        // Copia che non fa risparmiare: si butta e si serve l'originale.
        // Il metro dipende da cosa la copia sta davvero facendo — vedi
        // RENDITION_MIN_GAIN e RENDITION_SCALED_BYTES_SLACK: con meno
        // pixel il guadagno in memoria c'è comunque ed è il motivo per
        // cui la copia esiste; a parità di pixel l'unico guadagno
        // possibile è sul peso, e deve essere netto.
        $scaled = $newWidth < $width || $newHeight < $height;
        $sourceBytes = max(1, (int) filesize($srcPath));
        $outBytes = (int) filesize($tmpPath);
        $tooBig = $scaled
            ? $outBytes > $sourceBytes + RENDITION_SCALED_BYTES_SLACK
            : $outBytes > $sourceBytes * (1 - RENDITION_MIN_GAIN);
        if ($tooBig) {
            @unlink($tmpPath);
            return null;
        }

        @unlink($outPath);
        if (!@rename($tmpPath, $outPath)) {
            @unlink($tmpPath);
            return null;
        }

        return [
            'filename' => $outName,
            'filesize' => (int) filesize($outPath),
            'checksum' => (string) sha1_file($outPath),
            'width'    => $newWidth,
            'height'   => $newHeight,
            'mime'     => $keepAlpha ? 'image/png' : 'image/jpeg',
        ];
    } finally {
        imagedestroy($src);
    }
}

// Genera la copia per un media e la registra, se non c'è già. Ritorna la
// riga aggiornata. `rendition_at` segna il tentativo: valorizzato con
// `rendition_filename` a NULL significa "già provato, non serve o non si
// può" — così non si ritenta a ogni polling per sempre.
//
// I media caricati prima della 1.13.0 passano di qui la prima volta che
// una playlist li nomina: stessa pulizia opportunistica già usata da
// media_ensure_dimensions(), nessuna migrazione a parte da lanciare.
function media_ensure_rendition(array $media): array
{
    if (($media['type'] ?? '') !== 'image') {
        return $media;
    }
    // La colonna non c'è (file caricati prima della migrazione del
    // database): il sistema continua a servire gli originali
    if (!array_key_exists('rendition_at', $media)) {
        return $media;
    }
    if ($media['rendition_at'] !== null) {
        return $media; // già tentata: che sia riuscita o no, non si ripete
    }

    // Tetto per richiesta: vedi RENDITION_MAX_PER_REQUEST
    static $fatte = 0;
    if ($fatte >= RENDITION_MAX_PER_REQUEST) {
        return $media;
    }
    $fatte++;

    $filename = basename((string) $media['filename']);
    $result = null;
    try {
        $result = rendition_build(
            UPLOADS_DIR . '/' . $filename,
            (string) $media['mime_type'],
            UPLOADS_DIR,
            $filename,
        );
    } catch (Throwable $e) {
        // Una copia che non si riesce a fare non deve mai impedire a uno
        // schermo di ricevere la sua playlist: si annota e si va avanti
        // con l'originale, esattamente come prima della 1.13.0
        error_log('Frame Paring: copia di riproduzione fallita per il media '
            . (int) $media['id'] . ': ' . $e->getMessage());
    }

    try {
        db()->prepare('UPDATE media
                       SET rendition_filename = ?, rendition_filesize = ?,
                           rendition_checksum = ?, rendition_at = NOW()
                       WHERE id = ?')
            ->execute([
                $result['filename'] ?? null,
                $result['filesize'] ?? null,
                $result['checksum'] ?? null,
                (int) $media['id'],
            ]);
    } catch (Throwable $e) {
        error_log('Frame Paring: registrazione della copia di riproduzione fallita: ' . $e->getMessage());
        return $media;
    }

    $media['rendition_filename'] = $result['filename'] ?? null;
    $media['rendition_filesize'] = $result['filesize'] ?? null;
    $media['rendition_checksum'] = $result['checksum'] ?? null;
    $media['rendition_at'] = date('Y-m-d H:i:s');
    return $media;
}

// Quale file va consegnato davvero al dispositivo, con i suoi numeri.
// L'app usa `checksum` sia per validare il download sia come nome del
// file in cache: consegnare la copia con il checksum dell'originale
// significherebbe farsi scartare il file a ogni scaricamento.
function media_delivery(array $media): array
{
    $rendition = $media['rendition_filename'] ?? null;
    if ($rendition !== null && is_file(UPLOADS_DIR . '/' . basename((string) $rendition))) {
        return [
            'filename' => basename((string) $rendition),
            'filesize' => (int) $media['rendition_filesize'],
            'checksum' => (string) $media['rendition_checksum'],
            // Il MIME segue l'estensione della copia, non quello
            // dell'originale: una GIF ridotta viene servita come PNG, e
            // l'app sceglie da lì l'estensione del file in cache
            'mime'     => str_ends_with((string) $rendition, '.png') ? 'image/png' : 'image/jpeg',
        ];
    }
    return [
        'filename' => basename((string) $media['filename']),
        'filesize' => (int) $media['filesize'],
        'checksum' => (string) $media['checksum'],
        'mime'     => (string) $media['mime_type'],
    ];
}

// Elimina dal disco la copia di un media che sta per sparire. Chiamata
// dallo svuotamento del cestino, insieme all'originale.
function rendition_delete(array $media): void
{
    $rendition = $media['rendition_filename'] ?? null;
    if ($rendition === null || $rendition === '') {
        return;
    }
    $path = UPLOADS_DIR . '/' . basename((string) $rendition);
    if (is_file($path)) {
        @unlink($path);
    }
}
