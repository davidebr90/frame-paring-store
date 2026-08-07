<?php
declare(strict_types=1);

// Serve il logo personalizzato: uploads/ è chiusa al web.
// Pubblico (CMS_PUBLIC) perché serve anche a login e setup.
define('CMS_PUBLIC', true);
require_once __DIR__ . '/_cms.php';

$file = (string) branding('brand_logo');
$path = branding_dir() . '/' . basename($file);

if ($file === '' || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
if (!str_starts_with($mime, 'image/')) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
