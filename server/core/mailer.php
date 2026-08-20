<?php
declare(strict_types=1);

// Invio email: mail() nativa di PHP per difetto (funziona da sola su molto
// hosting condiviso, coerente con "zero Composer/vendor" del progetto),
// oppure un client SMTP minimale se l'amministratore configura un server
// SMTP dalla pagina Sistema → Email. Nessuna libreria esterna in entrambi
// i casi.

function mail_settings(): array
{
    return [
        'smtp_enabled'    => setting_get('smtp_enabled', '0') === '1',
        'smtp_host'       => (string) setting_get('smtp_host', ''),
        'smtp_port'       => (int) (setting_get('smtp_port', '587') ?: '587'),
        'smtp_encryption' => (string) setting_get('smtp_encryption', 'tls'), // none|ssl|tls
        'smtp_user'       => (string) setting_get('smtp_user', ''),
        'smtp_pass'       => (string) setting_get('smtp_pass', ''),
        'smtp_from_email' => (string) setting_get('smtp_from_email', ''),
        'smtp_from_name'  => (string) setting_get('smtp_from_name', ''),
    ];
}

// Ultimo errore di invio, per mostrarlo nella pagina "invia email di prova".
// Stesso pattern statico di current_user(): un dettaglio tecnico che serve
// solo subito dopo la chiamata, non vale una tabella o un parametro per
// riferimento in ogni firma. Unica funzione, doppio uso: senza argomenti
// legge, con un argomento scrive.
function mail_last_error(?string $set = null, bool $write = false): ?string
{
    static $stored = null;
    if ($write) {
        $stored = $set;
    }
    return $stored;
}

function mail_last_error_store(?string $value): void
{
    mail_last_error($value, true);
}

function send_mail(string $to, string $subject, string $body): bool
{
    mail_last_error_store(null);
    $settings = mail_settings();
    $fromEmail = $settings['smtp_from_email'] !== '' ? $settings['smtp_from_email'] : 'no-reply@' . mail_default_domain();
    $fromName = $settings['smtp_from_name'] !== '' ? $settings['smtp_from_name'] : branding('brand_name');

    if ($settings['smtp_enabled'] && $settings['smtp_host'] !== '') {
        return smtp_send($settings, $fromEmail, $fromName, $to, $subject, $body);
    }
    return native_mail_send($fromEmail, $fromName, $to, $subject, $body);
}

function mail_default_domain(): string
{
    $host = parse_url((string) config('base_url'), PHP_URL_HOST);
    return $host !== null && $host !== '' ? $host : 'localhost';
}

function mail_headers(string $fromEmail, string $fromName): string
{
    return "From: " . mail_encode_header($fromName) . " <{$fromEmail}>\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";
}

// Intestazioni email: solo ASCII per specifica, un nome del brand con
// accenti va incapsulato secondo RFC 2047
function mail_encode_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) === 1
        ? '=?UTF-8?B?' . base64_encode($value) . '?='
        : $value;
}

function native_mail_send(string $fromEmail, string $fromName, string $to, string $subject, string $body): bool
{
    $headers = mail_headers($fromEmail, $fromName);
    $ok = @mail($to, mail_encode_header($subject), $body, $headers, '-f' . $fromEmail);
    if (!$ok) {
        mail_last_error_store('mail() di PHP ha rifiutato l\'invio: verifica che il server abbia sendmail/postfix configurato.');
    }
    return $ok;
}

// Client SMTP minimale: connessione, EHLO, STARTTLS opzionale, AUTH LOGIN
// opzionale, un solo destinatario. Copre il caso d'uso (email di sistema
// verso un indirizzo alla volta), non un mail transfer agent completo.
function smtp_send(array $settings, string $fromEmail, string $fromName, string $to, string $subject, string $body): bool
{
    $scheme = $settings['smtp_encryption'] === 'ssl' ? 'ssl://' : '';
    $socket = @stream_socket_client(
        $scheme . $settings['smtp_host'] . ':' . $settings['smtp_port'],
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        mail_last_error_store("Connessione SMTP fallita: {$errstr} ({$errno})");
        return false;
    }

    try {
        smtp_expect($socket, 220);
        smtp_command($socket, 'EHLO ' . mail_default_domain(), 250);

        if ($settings['smtp_encryption'] === 'tls') {
            smtp_command($socket, 'STARTTLS', 220);
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Avvio TLS fallito');
            }
            smtp_command($socket, 'EHLO ' . mail_default_domain(), 250);
        }

        if ($settings['smtp_user'] !== '') {
            smtp_command($socket, 'AUTH LOGIN', 334);
            smtp_command($socket, base64_encode($settings['smtp_user']), 334);
            smtp_command($socket, base64_encode($settings['smtp_pass']), 235);
        }

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', 250);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', 250);
        smtp_command($socket, 'DATA', 354);

        $data = mail_headers($fromEmail, $fromName)
            . 'Subject: ' . mail_encode_header($subject) . "\r\n"
            . "To: {$to}\r\n"
            . "\r\n"
            . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))
            . "\r\n.";
        smtp_command($socket, $data, 250);

        smtp_command($socket, 'QUIT', 221);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        mail_last_error_store($e->getMessage());
        fclose($socket);
        return false;
    }
}

function smtp_command($socket, string $command, int $expectedCode): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCode);
}

function smtp_expect($socket, int $expectedCode): string
{
    $response = '';
    while (($line = fgets($socket, 512)) !== false) {
        $response .= $line;
        // Le righe di continuazione hanno un trattino dopo il codice
        // ("250-STARTTLS"), l'ultima uno spazio ("250 OK")
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if ($code !== $expectedCode) {
        throw new RuntimeException("Risposta SMTP inattesa: " . trim($response));
    }
    return $response;
}
