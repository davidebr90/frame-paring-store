<?php
declare(strict_types=1);

// Ruoli e capabilities del CMS.
// Un utente 'admin' ha SEMPRE tutti i permessi (non è personalizzabile:
// evita di restare chiusi fuori dal proprio pannello). Gli altri ruoli
// partono dal set predefinito, sovrascrivibile per singolo utente.

// Le costanti elencano i permessi; le etichette leggibili stanno nei
// cataloghi delle traduzioni e si ottengono con cap_label()/role_label().
const CAPABILITIES = [
    'media.upload'    => 'cap.media.upload',
    'media.edit'      => 'cap.media.edit',
    'gallery.manage'  => 'cap.gallery.manage',
    'screen.pair'     => 'cap.screen.pair',
    'screen.manage'   => 'cap.screen.manage',
    'schedule.manage' => 'cap.schedule.manage',
    'users.manage'    => 'cap.users.manage',
    'settings.manage' => 'cap.settings.manage',
];

const ROLE_LABELS = [
    'admin'    => 'role.admin',
    'editor'   => 'role.editor',
    'operator' => 'role.operator',
];

const ROLE_CAPABILITIES = [
    // admin: tutte (gestito in user_capabilities, non elencato qui)
    'admin' => null,
    'editor' => [
        'media.upload', 'media.edit', 'gallery.manage',
        'screen.manage', 'schedule.manage',
    ],
    'operator' => [
        'media.upload', 'media.edit',
    ],
];

// Capabilities effettive di un utente: override personale se presente,
// altrimenti il set del ruolo. L'admin le ha tutte, punto.
function user_capabilities(array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        return array_keys(CAPABILITIES);
    }

    if (!empty($user['capabilities_json'])) {
        $decoded = json_decode((string) $user['capabilities_json'], true);
        if (is_array($decoded)) {
            return array_values(array_intersect($decoded, array_keys(CAPABILITIES)));
        }
    }

    return ROLE_CAPABILITIES[$user['role'] ?? 'operator'] ?? [];
}

function current_can(string $capability): bool
{
    $user = current_user();
    return $user !== null && in_array($capability, user_capabilities($user), true);
}

// Guardia per le pagine: blocca chi non ha il permesso.
// Le richieste AJAX ricevono JSON, le pagine un messaggio leggibile.
function require_cap(string $capability): void
{
    if (current_can($capability)) {
        return;
    }
    if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        json_error('Permesso negato per questa operazione', 403);
    }
    flash_set('err', 'Non hai i permessi per questa operazione');
    redirect('index.php');
}

// Etichette leggibili, tradotte nella lingua in uso
function cap_label(string $key): string
{
    return isset(CAPABILITIES[$key]) ? t(CAPABILITIES[$key]) : $key;
}

function role_label(string $role): string
{
    return isset(ROLE_LABELS[$role]) ? t(ROLE_LABELS[$role]) : $role;
}

// Elenco permessi => etichetta, nell'ordine di dichiarazione
function capability_labels(): array
{
    return array_map(fn (string $k): string => t($k), CAPABILITIES);
}

function role_label_list(): array
{
    return array_map(fn (string $k): string => t($k), ROLE_LABELS);
}
