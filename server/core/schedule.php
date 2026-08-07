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

// Programmazione settimanale di uno schermo: fasce orarie con i giorni in
// cui valgono. Sta in `screens.schedule_json` come elenco di oggetti:
//
//   [{"from":"09:00","to":"20:00","days":[1,2,3,4,5,6]}]
//
// I giorni seguono la numerazione ISO: 1 = lunedì … 7 = domenica.
// Una fascia SENZA il campo `days` vale tutti i giorni: è così che restano
// valide le programmazioni salvate prima dell'introduzione dei giorni,
// senza toccare il database.

const SCHEDULE_DAYS = [1, 2, 3, 4, 5, 6, 7];

// Etichette dei giorni: chiavi di traduzione, risolte al momento di stampare
const SCHEDULE_DAY_KEYS = [
    1 => 'day.mon', 2 => 'day.tue', 3 => 'day.wed', 4 => 'day.thu',
    5 => 'day.fri', 6 => 'day.sat', 7 => 'day.sun',
];

function schedule_day_labels(bool $short = false): array
{
    $out = [];
    foreach (SCHEDULE_DAY_KEYS as $n => $key) {
        $out[$n] = t($short ? $key . '.short' : $key);
    }
    return $out;
}

function schedule_minutes(string $hhmm): int
{
    return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
}

// Legge il JSON salvato e ne ricava fasce normalizzate. Scarta in silenzio
// ciò che non è valido: una programmazione illeggibile non deve impedire
// allo schermo di funzionare.
function schedule_parse(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $bands = [];
    foreach ($decoded as $band) {
        $clean = schedule_clean_band(is_array($band) ? $band : []);
        if ($clean !== null) {
            $bands[] = $clean;
        }
    }
    return $bands;
}

// Normalizza una singola fascia. Ritorna null se non è utilizzabile.
function schedule_clean_band(array $band): ?array
{
    $from = (string) ($band['from'] ?? '');
    $to   = (string) ($band['to'] ?? '');
    $ok = static fn (string $v): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;

    if (!$ok($from) || !$ok($to) || $from === $to) {
        return null;
    }

    // Nessun giorno indicato = tutti i giorni (fasce salvate prima che i
    // giorni esistessero, oppure "tutti i giorni" scelto esplicitamente)
    $days = $band['days'] ?? null;
    if (!is_array($days)) {
        $days = SCHEDULE_DAYS;
    } else {
        $days = array_values(array_unique(array_filter(
            array_map('intval', $days),
            static fn (int $d): bool => $d >= 1 && $d <= 7
        )));
        sort($days);
    }
    if ($days === []) {
        return null; // una fascia senza giorni non si accenderebbe mai
    }

    return ['from' => $from, 'to' => $to, 'days' => $days];
}

function schedule_encode(array $bands): ?string
{
    return $bands === [] ? null : json_encode(array_values($bands));
}

// La fascia è attiva in quel momento?
//
// Una fascia che scavalca la mezzanotte (es. 22:00 → 06:00) COMINCIA nel
// giorno selezionato e finisce il mattino dopo: scegliendo il sabato,
// l'accensione copre sabato sera e le prime ore di domenica.
function schedule_band_active(array $band, int $dow, int $minute): bool
{
    $from = schedule_minutes($band['from']);
    $to   = schedule_minutes($band['to']);
    $days = $band['days'];

    if ($from < $to) {
        return in_array($dow, $days, true) && $minute >= $from && $minute < $to;
    }

    // Scavalca la mezzanotte: o è la sera del giorno scelto…
    if (in_array($dow, $days, true) && $minute >= $from) {
        return true;
    }
    // …oppure è la mattina successiva a un giorno scelto
    $ieri = $dow === 1 ? 7 : $dow - 1;
    return in_array($ieri, $days, true) && $minute < $to;
}

// Nessuna fascia = sempre acceso, come prima dei giorni.
function schedule_active_at(array $bands, int $dow, int $minute): bool
{
    if ($bands === []) {
        return true;
    }
    foreach ($bands as $band) {
        if (schedule_band_active($band, $dow, $minute)) {
            return true;
        }
    }
    return false;
}

function schedule_active_now(array $bands): bool
{
    $dow = (int) date('N');
    return schedule_active_at($bands, $dow, (int) date('G') * 60 + (int) date('i'));
}

// Griglia settimanale 7 giorni × 24 ore: per ogni cella dice se in
// quell'ora lo schermo è acceso almeno in parte. Serve all'anteprima.
function schedule_grid(array $bands): array
{
    $grid = [];
    foreach (SCHEDULE_DAYS as $dow) {
        for ($ora = 0; $ora < 24; $ora++) {
            $acceso = false;
            // Si campiona ogni quarto d'ora: una fascia 09:15-09:45 deve
            // comunque colorare la cella delle 9
            for ($m = 0; $m < 60; $m += 15) {
                if (schedule_active_at($bands, $dow, $ora * 60 + $m)) {
                    $acceso = true;
                    break;
                }
            }
            $grid[$dow][$ora] = $acceso;
        }
    }
    return $grid;
}

// Riassunto leggibile, del tipo "Lun–Ven 09:00–20:00 · Sab 10:00–13:00".
// Le fasce con gli stessi orari vengono raggruppate.
function schedule_summary(array $bands): string
{
    if ($bands === []) {
        return t('sch.always');
    }

    $pezzi = [];
    foreach ($bands as $band) {
        $pezzi[] = schedule_days_label($band['days']) . ' ' . $band['from'] . '–' . $band['to'];
    }
    return implode(' · ', $pezzi);
}

// "Tutti i giorni", "Lun–Ven", "Lun, Mer, Ven": si accorpano i giorni
// consecutivi, che è come li legge una persona.
function schedule_days_label(array $days): string
{
    sort($days);
    if (count($days) === 7) {
        return t('sch.every_day');
    }
    if ($days === [1, 2, 3, 4, 5]) {
        return t('sch.weekdays');
    }
    if ($days === [6, 7]) {
        return t('sch.weekend');
    }

    $nomi = schedule_day_labels(true);
    $gruppi = [];
    $inizio = $prec = null;
    foreach ($days as $d) {
        if ($inizio === null) {
            $inizio = $prec = $d;
            continue;
        }
        if ($d === $prec + 1) {
            $prec = $d;
            continue;
        }
        $gruppi[] = [$inizio, $prec];
        $inizio = $prec = $d;
    }
    if ($inizio !== null) {
        $gruppi[] = [$inizio, $prec];
    }

    $out = [];
    foreach ($gruppi as [$a, $b]) {
        // Due giorni consecutivi si leggono meglio elencati che con il trattino
        $out[] = $a === $b ? $nomi[$a] : ($b - $a === 1 ? $nomi[$a] . ', ' . $nomi[$b] : $nomi[$a] . '–' . $nomi[$b]);
    }
    return implode(', ', $out);
}

// Modelli pronti: coprono i casi che si incontrano davvero in un negozio.
function schedule_presets(): array
{
    return [
        'always' => [
            'label' => t('sch.preset_always'),
            'bands' => [],
        ],
        'shop' => [
            'label' => t('sch.preset_shop'),
            'bands' => [['from' => '09:00', 'to' => '20:00', 'days' => [1, 2, 3, 4, 5, 6]]],
        ],
        'shop_break' => [
            'label' => t('sch.preset_shop_break'),
            'bands' => [
                ['from' => '09:00', 'to' => '13:00', 'days' => [1, 2, 3, 4, 5, 6]],
                ['from' => '16:00', 'to' => '20:00', 'days' => [1, 2, 3, 4, 5, 6]],
            ],
        ],
        'office' => [
            'label' => t('sch.preset_office'),
            'bands' => [['from' => '08:30', 'to' => '18:30', 'days' => [1, 2, 3, 4, 5]]],
        ],
        'weekend' => [
            'label' => t('sch.preset_weekend'),
            'bands' => [['from' => '10:00', 'to' => '23:00', 'days' => [6, 7]]],
        ],
        'window' => [
            'label' => t('sch.preset_window'),
            'bands' => [['from' => '07:00', 'to' => '23:59', 'days' => SCHEDULE_DAYS]],
        ],
    ];
}

// Ore complessive di accensione in una settimana: dà la misura concreta di
// quanto resterà acceso il pannello.
function schedule_weekly_hours(array $bands): float
{
    if ($bands === []) {
        return 24 * 7;
    }
    $minuti = 0;
    foreach (SCHEDULE_DAYS as $dow) {
        for ($m = 0; $m < 1440; $m += 5) {
            if (schedule_active_at($bands, $dow, $m)) {
                $minuti += 5;
            }
        }
    }
    return round($minuti / 60, 1);
}
