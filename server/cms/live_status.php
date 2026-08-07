<?php
declare(strict_types=1);

// Stato delle dirette per la spia nel menu. Interrogato dal CMS a intervalli
// regolari così la spia resta veritiera anche senza ricaricare la pagina.
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(live_status(), JSON_UNESCAPED_UNICODE);
