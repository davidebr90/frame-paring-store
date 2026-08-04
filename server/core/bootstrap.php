<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('UPLOADS_DIR', APP_ROOT . '/uploads');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configurazione mancante: copiare core/config.sample.php in core/config.php');
}
$GLOBALS['config'] = require $configFile;

require __DIR__ . '/version.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/screens.php';

function config(string $key): mixed
{
    return $GLOBALS['config'][$key] ?? null;
}
