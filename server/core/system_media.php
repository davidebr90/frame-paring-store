<?php
declare(strict_types=1);

// File di servizio forniti con il sistema: sfondi pronti per le sospensioni
// (fuori fascia oraria, riempimento, oscuramento di uno schermo in diretta).
//
// Sta in un file a sé perché serve a due mondi che non si conoscono:
// core/screens.php, che lavora sul database già configurato, e
// core/installer.php, che gira PRIMA che una configurazione esista.
const SYSTEM_MEDIA = [
    'nero.png'   => 'sys.media.nero',
    'bianco.png' => 'sys.media.bianco',
];

function system_media_dir(): string
{
    return __DIR__ . '/system-media';
}
