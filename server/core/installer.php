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

// Logica dell'installazione guidata, separata dalla pagina che la mostra.
//
// Deve funzionare SENZA core/config.php: è proprio il file che si occupa di
// scrivere. Quindi qui non si può usare bootstrap.php né db().

require_once __DIR__ . '/system_media.php';

const INSTALL_MIN_PHP = '8.0.0';

// Requisiti dell'ambiente. Ognuno dichiara se è indispensabile: senza i
// requisiti bloccanti non ha senso proseguire, gli altri sono avvisi.
function install_requirements(): array
{
    $uploads = dirname(__DIR__) . '/uploads';
    $coreDir = __DIR__;

    return [
        [
            'label'    => t('inst.req_php'),
            'ok'       => version_compare(PHP_VERSION, INSTALL_MIN_PHP, '>='),
            'detail'   => PHP_VERSION,
            'blocking' => true,
            'howto'    => t('inst.req_php_howto', ['min' => INSTALL_MIN_PHP]),
        ],
        [
            'label'    => t('inst.req_pdo'),
            'ok'       => extension_loaded('pdo_mysql'),
            'detail'   => extension_loaded('pdo_mysql') ? t('inst.present') : t('inst.missing'),
            'blocking' => true,
            'howto'    => t('inst.req_pdo_howto'),
        ],
        [
            'label'    => t('inst.req_fileinfo'),
            'ok'       => extension_loaded('fileinfo'),
            'detail'   => extension_loaded('fileinfo') ? t('inst.present') : t('inst.missing'),
            'blocking' => true,
            'howto'    => t('inst.req_fileinfo_howto'),
        ],
        [
            'label'    => t('inst.req_uploads'),
            'ok'       => is_dir($uploads) && is_writable($uploads),
            'detail'   => is_dir($uploads)
                ? (is_writable($uploads) ? t('inst.writable') : t('inst.not_writable'))
                : t('inst.missing'),
            'blocking' => false,
            'howto'    => t('inst.req_uploads_howto'),
        ],
        [
            'label'    => t('inst.req_core'),
            'ok'       => is_writable($coreDir),
            'detail'   => is_writable($coreDir) ? t('inst.writable') : t('inst.not_writable'),
            'blocking' => false, // c'è il ripiego manuale via FTP
            'howto'    => t('inst.req_core_howto'),
        ],
    ];
}

function install_blocked(array $requirements): bool
{
    foreach ($requirements as $r) {
        if ($r['blocking'] && !$r['ok']) {
            return true;
        }
    }
    return false;
}

// Prova la connessione. Ritorna [PDO|null, messaggio d'errore|null].
function install_connect(string $host, string $name, string $user, string $pass): array
{
    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        return [$pdo, null];
    } catch (PDOException $e) {
        // Il messaggio grezzo di MySQL è tecnico ma è l'unica informazione
        // utile per capire cosa non va: si mostra tradotto e con il dettaglio
        return [null, t('inst.db_error', ['detail' => $e->getMessage()])];
    }
}

// Le tabelle che lo schema deve creare. Serve a capire se il database è
// già popolato (installazione ripetuta) e a verificare l'importazione.
const INSTALL_TABLES = [
    'settings', 'users', 'screens', 'galleries', 'media',
    'screen_galleries', 'live_overrides', 'live_override_screens',
    'login_attempts',
];

function install_existing_tables(PDO $pdo): array
{
    $found = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (in_array($table, INSTALL_TABLES, true)) {
            $found[] = $table;
        }
    }
    return $found;
}

// Divide lo schema nelle singole istruzioni.
//
// Spezzare su ';' e basta non funziona: i commenti in coda alla riga
// (`... ); -- nota`) diventerebbero istruzioni a sé, e un ';' dentro una
// stringa taglierebbe l'istruzione a metà. Si scorre quindi il testo
// tenendo conto di apici, virgolette e apici inversi.
function install_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;          // apice aperto: ' " oppure `
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($quote !== null) {
            $current .= $c;
            // Apice raddoppiato dentro una stringa: non la chiude
            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $current .= $sql[++$i];
                } else {
                    $quote = null;
                }
            } elseif ($c === '\\' && $quote !== '`') {
                $current .= $sql[++$i] ?? '';
            }
            continue;
        }

        // Commento di riga: dallo standard SQL sono due trattini seguiti da
        // uno spazio. Si salta fino a fine riga.
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-'
            && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\r", "\n"], true)) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $current .= "\n";
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $current .= $c;
            continue;
        }

        if ($c === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

// Importa database/schema.sql.
function install_import_schema(PDO $pdo): array
{
    $file = dirname(__DIR__, 2) . '/database/schema.sql';
    if (!is_file($file)) {
        // Nel pacchetto caricato via FTP la cartella database/ può mancare:
        // si accetta anche una copia messa accanto al codice del server
        $file = dirname(__DIR__) . '/schema.sql';
    }
    if (!is_file($file)) {
        return [false, t('inst.schema_missing')];
    }

    try {
        foreach (install_split_sql((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
        }
    } catch (PDOException $e) {
        return [false, t('inst.schema_error', ['detail' => $e->getMessage()])];
    }

    $missing = array_diff(INSTALL_TABLES, install_existing_tables($pdo));
    if ($missing) {
        return [false, t('inst.schema_incomplete', ['tables' => implode(', ', $missing)])];
    }

    // Galleria di servizio con gli sfondi pronti: meglio trovarla già lì
    // che doversi fabbricare un PNG nero al primo schermo da sospendere.
    install_seed_system_gallery($pdo);
    return [true, null];
}

// Crea la galleria di servizio e vi copia gli sfondi standard. Qui non si
// può usare courtesy_gallery_id(): quella passa da db(), che a sua volta
// pretende la configurazione che stiamo ancora scrivendo.
function install_seed_system_gallery(PDO $pdo): void
{
    $esiste = $pdo->query("SELECT id FROM galleries WHERE system_key = 'courtesy'")->fetchColumn();
    if ($esiste !== false) {
        return;
    }
    $pdo->prepare("INSERT INTO galleries (name, system_key) VALUES (?, 'courtesy')")
        ->execute([t('sys.gallery_name')]);
    $galleryId = (int) $pdo->lastInsertId();

    $uploads = dirname(__DIR__) . '/uploads';
    foreach (SYSTEM_MEDIA as $file => $chiave) {
        $origine = system_media_dir() . '/' . $file;
        if (!is_file($origine)) {
            continue;
        }
        $nome = bin2hex(random_bytes(16)) . '.png';
        if (!@copy($origine, $uploads . '/' . $nome)) {
            continue; // uploads non scrivibile: si popolerà al primo accesso al CMS
        }
        $pdo->prepare('INSERT INTO media
                       (gallery_id, type, filename, original_name, mime_type,
                        filesize, checksum, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $galleryId, 'image', $nome, t($chiave), 'image/png',
                filesize($origine), sha1_file($origine), 0,
            ]);
    }
}

// Indirizzo pubblico della cartella server/, dedotto dalla richiesta in
// corso. È quasi sempre giusto; resta modificabile prima di salvare.
function install_guess_base_url(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $proto = $https || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir   = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $proto . '://' . $host . $dir;
}

// Contenuto di core/config.php. Anche quando non si riesce a scriverlo, si
// mostra all'utente da copiare via FTP: l'installazione non si blocca mai
// per un permesso mancante.
function install_config_contents(array $values): string
{
    $q = static fn (string $s): string => var_export($s, true);

    return <<<PHP
    <?php
    declare(strict_types=1);

    // Configurazione generata dall'installazione guidata.
    // Questo file NON è nel repository: un aggiornamento non lo sovrascrive.
    return [
        'db' => [
            'host' => {$q($values['db_host'])},
            'name' => {$q($values['db_name'])},
            'user' => {$q($values['db_user'])},
            'pass' => {$q($values['db_pass'])},
        ],

        // URL pubblico della cartella server/ SENZA slash finale
        'base_url' => {$q($values['base_url'])},

        // Validità del PIN/QR di pairing, in secondi
        'pairing_ttl' => {$values['pairing_ttl']},

        // Ogni quanti secondi l'app interroga il server
        'poll_interval' => {$values['poll_interval']},

        // Controllo di stato: risposta di poche centinaia di byte
        'state_interval' => {$values['state_interval']},

        // Dimensione massima upload media, in byte
        'max_upload_bytes' => {$values['max_upload_bytes']},
    ];

    PHP;
}

function install_write_config(string $contents): bool
{
    $file = __DIR__ . '/config.php';
    return @file_put_contents($file, $contents) !== false;
}

function install_config_exists(): bool
{
    return is_file(__DIR__ . '/config.php');
}

// L'installazione è già stata fatta? Config presente E un amministratore
// creato: solo allora la procedura va chiusa a chiave.
function install_already_done(): bool
{
    if (!install_config_exists()) {
        return false;
    }
    $config = @include __DIR__ . '/config.php';
    if (!is_array($config) || !isset($config['db'])) {
        return false;
    }
    [$pdo] = install_connect(
        (string) $config['db']['host'],
        (string) $config['db']['name'],
        (string) $config['db']['user'],
        (string) $config['db']['pass'],
    );
    if ($pdo === null) {
        return false;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException) {
        return false; // tabella assente: l'installazione non è completa
    }
}

// Limite di upload sensato: il minimo fra quanto dichiara PHP e 200 MB.
// Impostarne uno superiore ai limiti dell'hosting produrrebbe solo upload
// che falliscono a metà.
function install_default_upload_bytes(): int
{
    $toBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $n = (int) $value;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    };

    $limits = array_filter([
        $toBytes((string) ini_get('upload_max_filesize')),
        $toBytes((string) ini_get('post_max_size')),
    ]);
    $limits[] = 200 * 1024 * 1024;
    return min($limits);
}
