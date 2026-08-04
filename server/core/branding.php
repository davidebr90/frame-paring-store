<?php
declare(strict_types=1);

// White labeling: nome, logo e colore del CMS e dell'app TV.
// Tutto è salvato in `settings`; i file (logo) in uploads/branding/.

const BRANDING_DEFAULTS = [
    'brand_name'        => APP_NAME_DEFAULT, // titolo del CMS e nome mostrato sulla TV
    'brand_tagline'     => 'Digital signage — pannello di controllo',
    'brand_accent'      => '#ffb224',
    'brand_logo'        => '',               // nome file in uploads/branding/
    'brand_tv_title'    => '',               // vuoto = usa brand_name
    'brand_tv_subtitle' => 'Configurazione schermo',
    'brand_footer_note' => '',               // riga libera nel footer del CMS
];

function branding(?string $key = null): array|string
{
    static $cache = null;
    if ($cache === null) {
        $cache = BRANDING_DEFAULTS;
        foreach (BRANDING_DEFAULTS as $k => $default) {
            $value = setting_get($k);
            if ($value !== null && $value !== '') {
                $cache[$k] = $value;
            }
        }
    }
    return $key === null ? $cache : ($cache[$key] ?? '');
}

function branding_dir(): string
{
    $dir = UPLOADS_DIR . '/branding';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

// URL del logo per il CMS (servito da brand_logo.php, uploads è chiusa al web)
function branding_logo_url(): ?string
{
    $file = (string) branding('brand_logo');
    if ($file === '' || !is_file(branding_dir() . '/' . basename($file))) {
        return null;
    }
    return 'brand_logo.php?v=' . substr(md5($file), 0, 8);
}

// Il nome mostrato sulla TV: brand_tv_title se valorizzato, altrimenti brand_name
function branding_tv_title(): string
{
    $title = (string) branding('brand_tv_title');
    return $title !== '' ? $title : (string) branding('brand_name');
}

// Colore accento validato: deve restare un colore CSS sicuro
function branding_accent(): string
{
    $accent = (string) branding('brand_accent');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : '#ffb224';
}

// Colore del testo su fondo accento: nero o bianco secondo la luminanza
function branding_accent_ink(): string
{
    $hex = ltrim(branding_accent(), '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.6 ? '#1a1205' : '#ffffff';
}
