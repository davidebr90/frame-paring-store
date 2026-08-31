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

// Banner in sovraimpressione: un riquadro discreto sopra il contenuto, con
// un logo e/o la data e l'ora. È disattivato di default e non tocca in alcun
// modo la riproduzione: resta un livello grafico sopra tutto il resto.
//
// La data e l'ora vengono composte dalla TV con l'orologio locale, non dal
// server: così restano corrette anche a rete assente e cambiano da sole a
// mezzanotte senza attendere un polling.

require_once __DIR__ . '/trash.php';    // setting_get() / setting_set()
require_once __DIR__ . '/branding.php'; // branding_dir(): il logo sta lì

const OVERLAY_DEFAULTS = [
    'overlay_enabled'     => '0',
    'overlay_position'    => 'bottom_right',
    'overlay_show_logo'   => '0',
    'overlay_logo'        => '',       // nome file in uploads/branding/
    'overlay_show_date'   => '1',
    'overlay_show_time'   => '1',
    'overlay_date_format' => 'short',  // short: 24/06/2026 — long: 24 Giugno 2026
    'overlay_size'        => 'medium',
    'overlay_opacity'     => '80',     // opacità del fondo, 0-100
];

// Nove ancoraggi: le tre fasce orizzontali per le tre verticali
const OVERLAY_POSITIONS = [
    'top_left'      => 'ov.pos.top_left',
    'top_center'    => 'ov.pos.top_center',
    'top_right'     => 'ov.pos.top_right',
    'middle_left'   => 'ov.pos.middle_left',
    'center'        => 'ov.pos.center',
    'middle_right'  => 'ov.pos.middle_right',
    'bottom_left'   => 'ov.pos.bottom_left',
    'bottom_center' => 'ov.pos.bottom_center',
    'bottom_right'  => 'ov.pos.bottom_right',
];

const OVERLAY_SIZES = [
    'small'  => 'ov.size.small',
    'medium' => 'ov.size.medium',
    'large'  => 'ov.size.large',
];

const OVERLAY_DATE_FORMATS = [
    'short' => 'ov.fmt.short',
    'long'  => 'ov.fmt.long',
];

// Elenchi chiave => etichetta tradotta, per i menu del pannello
function overlay_position_labels(): array
{
    return array_map(fn (string $k): string => t($k), OVERLAY_POSITIONS);
}

function overlay_size_labels(): array
{
    return array_map(fn (string $k): string => t($k), OVERLAY_SIZES);
}

function overlay_format_labels(): array
{
    return array_map(fn (string $k): string => t($k), OVERLAY_DATE_FORMATS);
}

// I mesi si traducono: la lingua del pannello e quella degli schermi
// possono essere diverse, quindi ogni lato usa il proprio catalogo.
function overlay_month(int $n): string
{
    return t('ov.month.' . max(1, min(12, $n)));
}

function overlay(?string $key = null): array|string
{
    static $cache = null;
    if ($cache === null) {
        $cache = OVERLAY_DEFAULTS;
        foreach (OVERLAY_DEFAULTS as $k => $default) {
            $value = setting_get($k);
            if ($value !== null && $value !== '') {
                $cache[$k] = $value;
            }
        }
        // Valori fuori elenco (database modificato a mano) tornano al default
        $cache = overlay_normalize($cache);
    }
    return $key === null ? $cache : ($cache[$key] ?? '');
}

// Logo delle impostazioni generali
function overlay_logo_file(): ?string
{
    return overlay_logo_path(overlay());
}

// Anteprima testuale per il CMS, con le stesse regole che applica la TV
function overlay_sample_text(?array $settings = null): string
{
    $settings ??= overlay();
    $parts = [];
    if ((string) ($settings['overlay_show_date'] ?? '0') === '1') {
        $parts[] = (string) ($settings['overlay_date_format'] ?? 'short') === 'long'
            ? (int) date('j') . ' ' . overlay_month((int) date('n')) . ' ' . date('Y')
            : date('d/m/Y');
    }
    if ((string) ($settings['overlay_show_time'] ?? '0') === '1') {
        $parts[] = date('H:i');
    }
    return implode(' · ', $parts);
}

// ---------------------------------------------------------------------------
// Impostazioni per singolo schermo
//
// Ogni schermo eredita la configurazione generale finché non gliene viene
// data una propria. Personalizzarne uno non tocca gli altri, e si può
// sempre tornare all'eredità senza perdere le impostazioni generali.
// ---------------------------------------------------------------------------

// Lo schermo ha una configurazione propria?
function overlay_is_custom(array $screen): bool
{
    return ($screen['overlay_json'] ?? null) !== null;
}

// Valori normalizzati: quelli dello schermo se personalizzato, altrimenti
// quelli generali. Il risultato ha sempre la forma di OVERLAY_DEFAULTS.
function overlay_settings_for_screen(array $screen): array
{
    if (!overlay_is_custom($screen)) {
        return overlay();
    }
    $decoded = json_decode((string) $screen['overlay_json'], true);
    if (!is_array($decoded)) {
        return overlay(); // dato illeggibile: si ricade sull'eredità
    }
    return overlay_normalize($decoded + OVERLAY_DEFAULTS);
}

// Riporta un insieme di valori dentro i limiti ammessi. Difende sia dai POST
// sia da un overlay_json scritto a mano nel database.
function overlay_normalize(array $values): array
{
    $out = OVERLAY_DEFAULTS;
    foreach (OVERLAY_DEFAULTS as $key => $default) {
        if (array_key_exists($key, $values) && $values[$key] !== null) {
            $out[$key] = (string) $values[$key];
        }
    }
    foreach (['overlay_enabled', 'overlay_show_logo', 'overlay_show_date', 'overlay_show_time'] as $flag) {
        $out[$flag] = $out[$flag] === '1' ? '1' : '0';
    }
    if (!isset(OVERLAY_POSITIONS[$out['overlay_position']])) {
        $out['overlay_position'] = OVERLAY_DEFAULTS['overlay_position'];
    }
    if (!isset(OVERLAY_SIZES[$out['overlay_size']])) {
        $out['overlay_size'] = OVERLAY_DEFAULTS['overlay_size'];
    }
    if (!isset(OVERLAY_DATE_FORMATS[$out['overlay_date_format']])) {
        $out['overlay_date_format'] = OVERLAY_DEFAULTS['overlay_date_format'];
    }
    $out['overlay_opacity'] = (string) max(0, min(100, (int) $out['overlay_opacity']));
    // Il nome del file non deve poter uscire dalla cartella dei loghi
    $out['overlay_logo'] = basename((string) $out['overlay_logo']);
    return $out;
}

function overlay_logo_path(array $settings): ?string
{
    $name = (string) ($settings['overlay_logo'] ?? '');
    if ($name === '') {
        return null;
    }
    $path = branding_dir() . '/' . basename($name);
    return is_file($path) ? $path : null;
}

// C'è davvero qualcosa da mostrare con questi valori?
function overlay_settings_active(array $settings): bool
{
    if ((string) ($settings['overlay_enabled'] ?? '0') !== '1') {
        return false;
    }
    return ((string) $settings['overlay_show_logo'] === '1' && overlay_logo_path($settings) !== null)
        || (string) $settings['overlay_show_date'] === '1'
        || (string) $settings['overlay_show_time'] === '1';
}

// Rappresentazione per un singolo schermo: è questa che finisce nella
// playlist. null quando quello schermo non deve mostrare nulla.
function overlay_payload_for_screen(array $screen): ?array
{
    $settings = overlay_settings_for_screen($screen);
    if (!overlay_settings_active($settings)) {
        return null;
    }
    $logo = (string) $settings['overlay_show_logo'] === '1' && overlay_logo_path($settings) !== null;

    return [
        'position'    => (string) $settings['overlay_position'],
        'size'        => (string) $settings['overlay_size'],
        'opacity'     => (int) $settings['overlay_opacity'],
        'show_date'   => (string) $settings['overlay_show_date'] === '1',
        'show_time'   => (string) $settings['overlay_show_time'] === '1',
        'date_format' => (string) $settings['overlay_date_format'],
        'logo_url'    => $logo ? config('base_url') . '/api/v1/overlay_logo.php' : null,
        // Cambia a ogni sostituzione del logo: guida la cache del dispositivo
        'logo_tag'    => $logo ? substr(sha1((string) $settings['overlay_logo']), 0, 16) : null,
    ];
}

// Accoglie il logo caricato ed elimina il precedente. Ritorna il nome del
// file nuovo, oppure un messaggio d'errore da mostrare. Condiviso fra le
// impostazioni generali e quelle di ogni schermo.
function overlay_store_logo(?array $file, string $previous): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, t('ovp.err_nofile')];
    }
    // Niente SVG: sulla TV il logo è disegnato da Android, che non lo rende
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return [null, t('ovp.err_format')];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, t('ovp.err_toobig')];
    }

    $name = 'banner-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], branding_dir() . '/' . $name)) {
        return [null, t('ovp.err_save')];
    }
    overlay_forget_logo($previous);
    return [$name, null];
}

// Elimina un logo che non è più usato da nessuno. Lo stesso file potrebbe
// essere condiviso (impostazioni generali + schermi): si tocca solo se
// davvero non lo riferisce più nessun altro.
function overlay_forget_logo(string $name): void
{
    $name = basename($name);
    if ($name === '' || !is_file(branding_dir() . '/' . $name)) {
        return;
    }
    if ((string) setting_get('overlay_logo') === $name) {
        return;
    }
    $stmt = db()->query('SELECT overlay_json FROM screens WHERE overlay_json IS NOT NULL');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $decoded = json_decode((string) $json, true);
        if (is_array($decoded) && basename((string) ($decoded['overlay_logo'] ?? '')) === $name) {
            return;
        }
    }
    unlink(branding_dir() . '/' . $name);
}

// Salva la configurazione di uno schermo. $values null = torna a ereditare.
function overlay_save_for_screen(int $screenId, ?array $values): void
{
    db()->prepare('UPDATE screens SET overlay_json = ? WHERE id = ?')
        ->execute([$values === null ? null : json_encode(overlay_normalize($values)), $screenId]);

    screen_event_add($screenId, 'config', 'overlay');
}
