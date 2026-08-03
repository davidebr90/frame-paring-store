<?php
declare(strict_types=1);

// Copiare questo file in core/config.php e compilare i valori.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'frame_paring_store',
        'user' => 'db_user',
        'pass' => 'db_password',
    ],

    // URL pubblico della cartella server/ SENZA slash finale,
    // es. 'https://tuodominio.it/signage'
    'base_url' => 'https://example.com',

    // Validità del PIN/QR di pairing, in secondi
    'pairing_ttl' => 300,

    // Ogni quanti secondi l'app interroga il server (pairing e playlist)
    'poll_interval' => 60,

    // Dimensione massima upload media, in byte (deve stare entro i
    // limiti upload_max_filesize/post_max_size dell'hosting)
    'max_upload_bytes' => 209715200, // 200 MB
];
