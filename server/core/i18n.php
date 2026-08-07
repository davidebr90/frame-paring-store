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

// Traduzioni dell'interfaccia.
//
// Aggiungere una lingua significa due cose e basta: un file in core/lang/
// che ritorna un array chiave => testo, e una riga in LANGUAGES. Le chiavi
// mancanti ricadono sull'italiano, così una traduzione incompleta non
// lascia mai un'etichetta vuota a schermo.

const LANG_FALLBACK = 'it';

// 'label' è il nome della lingua nella lingua stessa: chi non capisce
// quella corrente deve comunque riconoscere la propria.
const LANGUAGES = [
    'it' => ['label' => 'Italiano', 'region' => 'IT'],
    'en' => ['label' => 'English',  'region' => 'GB'],
];

// Bandiere disegnate in SVG, non emoji: su Windows le emoji delle bandiere
// non hanno glifo e il browser ripiega sulle due lettere del paese.
function lang_flag(string $code): string
{
    $flags = [
        'it' => '<svg viewBox="0 0 3 2" aria-hidden="true">'
              . '<rect width="1" height="2" x="0" fill="#008C45"/>'
              . '<rect width="1" height="2" x="1" fill="#F4F5F0"/>'
              . '<rect width="1" height="2" x="2" fill="#CD212A"/></svg>',
        'en' => '<svg viewBox="0 0 60 30" aria-hidden="true">'
              . '<rect width="60" height="30" fill="#012169"/>'
              . '<path d="M0,0 L60,30 M60,0 L0,30" stroke="#FFF" stroke-width="6"/>'
              . '<path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/>'
              . '<path d="M30,0 V30 M0,15 H60" stroke="#FFF" stroke-width="10"/>'
              . '<path d="M30,0 V30 M0,15 H60" stroke="#C8102E" stroke-width="6"/></svg>',
    ];
    return $flags[$code] ?? '';
}

// Lingua in uso per questa richiesta. Ordine di precedenza:
//   1. scelta esplicita nella sessione (selettore, anche prima del login)
//   2. preferenza dell'utente connesso
//   3. lingua predefinita dell'installazione
//   4. italiano
function lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    // current_user() esiste solo nel CMS: l'API non ha utenti connessi
    $user = function_exists('current_user') ? current_user() : null;

    $candidates = [
        $_SESSION['lang'] ?? null,
        $user['language'] ?? null,
        function_exists('setting_get') ? setting_get('default_language') : null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && isset(LANGUAGES[$candidate])) {
            return $lang = $candidate;
        }
    }
    return $lang = LANG_FALLBACK;
}

// Imposta la lingua per la sessione corrente. Ritorna false se sconosciuta.
function lang_set(string $code): bool
{
    if (!isset(LANGUAGES[$code])) {
        return false;
    }
    $_SESSION['lang'] = $code;
    return true;
}

function lang_catalogue(string $code): array
{
    static $cache = [];
    if (!isset($cache[$code])) {
        $file = __DIR__ . '/lang/' . $code . '.php';
        $cache[$code] = is_file($file) ? (array) require $file : [];
    }
    return $cache[$code];
}

// Testo tradotto. I segnaposto si scrivono :nome e si passano in $params;
// i valori vengono inseriti così come sono, l'escape resta a carico di chi
// stampa (come per qualsiasi altra stringa).
function t(string $key, array $params = []): string
{
    $catalogue = lang_catalogue(lang());
    $text = $catalogue[$key]
        ?? lang_catalogue(LANG_FALLBACK)[$key]
        // Chiave assente ovunque: si mostra la chiave, che è sempre più
        // utile di uno spazio vuoto e salta subito all'occhio in revisione
        ?? $key;

    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }
    return $text;
}

// Elenco delle lingue disponibili, per il selettore
function lang_options(): array
{
    return LANGUAGES;
}
