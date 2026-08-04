-- ============================================================
-- FRAME PARING STORE — Schema database
-- MySQL 5.7+ / MariaDB 10.3+ — importabile via phpMyAdmin
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Impostazioni di sistema (chiave/valore)
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `key`   VARCHAR(60) NOT NULL,
  `value` TEXT        NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('trash_retention_days', '30');  -- 0 = non svuotare mai il cestino

-- ------------------------------------------------------------
-- Utenti amministratori del CMS
-- ------------------------------------------------------------
-- role: 'admin' ha sempre tutte le capabilities; 'editor' e 'operator'
-- partono da un set predefinito, personalizzabile per singolo utente
-- tramite capabilities_json (NULL = usa il set del ruolo).
CREATE TABLE `users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)      NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL,           -- password_hash() PHP (bcrypt/argon2)
  `full_name`     VARCHAR(120)     NULL,               -- anagrafica
  `email`         VARCHAR(190)     NULL,
  `phone`         VARCHAR(40)      NULL,
  `note`          VARCHAR(255)     NULL,
  `role`          ENUM('admin','editor','operator') NOT NULL DEFAULT 'operator',
  `capabilities_json` TEXT         NULL,               -- override puntuali; NULL = default del ruolo
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1, -- disattivazione senza cancellare la storia
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME         NULL,
  `deleted_at`    DATETIME         NULL,   -- cestino: NULL = utente attivo
  `deleted_by`    INT UNSIGNED     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Schermi (dispositivi Android TV / Fire Stick)
--
-- Tre metodi di pairing, tutti convergono su auth_token:
--  1) PIN: la TV chiama l'API -> riga 'pending' con pairing_code
--     a 6 cifre mostrato a schermo; l'admin lo digita nel CMS.
--  2) QR: stesso flusso del PIN, ma la TV mostra anche un QR
--     che apre il CMS con il codice precompilato (si scansiona
--     col telefono e si conferma da lì). Nessun campo in più.
--  3) Manuale: l'admin crea lo schermo nel CMS PRIMA, ottiene
--     una URL dedicata contenente claim_token; la si inserisce
--     nelle impostazioni dell'app e la TV si autorizza da sola.
-- ------------------------------------------------------------
CREATE TABLE `screens` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(100)  NOT NULL DEFAULT 'Nuovo schermo',
  `pairing_code`     CHAR(6)       NULL,               -- PIN mostrato sulla TV (anche dentro il QR), azzerato dopo il pairing
  `pairing_expires_at` DATETIME    NULL,               -- scadenza del PIN/QR
  `poll_token`       CHAR(64)      NULL,               -- segreto noto solo al dispositivo richiedente: serve a
                                                       -- ritirare l'auth_token (il PIN da solo non basta)
  `claim_token`      CHAR(64)      NULL,               -- token della URL dedicata (metodo manuale), azzerato dopo l'uso
  `pair_method`      ENUM('pin','qr','manual') NULL,   -- come è avvenuto il pairing (NULL finché pending)
  `auth_token`       CHAR(64)      NULL,               -- token segreto rilasciato al pairing (hex 256 bit)
  `status`           ENUM('pending','paired','revoked') NOT NULL DEFAULT 'pending',
  `device_info`      VARCHAR(255)  NULL,               -- modello/OS dichiarati dall'app
  `diagonal_inches`  SMALLINT UNSIGNED NULL,           -- polliciaggio del pannello fisico (es. 65)
  `orientation`      ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape', -- montaggio fisico
  `schedule_json`    TEXT          NULL,               -- fasce orarie attive [{"from":"09:00","to":"20:00"},...]; NULL = sempre attivo
  `idle_mode`        ENUM('black','image') NOT NULL DEFAULT 'black', -- fuori fascia: nero (spento apparente) o immagine di cortesia
  `idle_media_id`    INT UNSIGNED  NULL,               -- immagine di cortesia (un media immagine)
  `last_seen_at`     DATETIME      NULL,               -- ultimo polling ricevuto
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_screens_pairing_code` (`pairing_code`),
  UNIQUE KEY `uq_screens_poll_token` (`poll_token`),
  UNIQUE KEY `uq_screens_claim_token` (`claim_token`),
  UNIQUE KEY `uq_screens_auth_token` (`auth_token`),
  KEY `idx_screens_idle_media` (`idle_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- FK aggiunta dopo la creazione di `media` (dipendenza circolare screens<->media)
-- ALTER TABLE screens ADD CONSTRAINT fk_screens_idle_media
--   FOREIGN KEY (idle_media_id) REFERENCES media (id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- Gallerie (playlist di media)
-- updated_at avanza a ogni modifica di contenuto/ordine:
-- l'app la usa per capire se scaricare di nuovo la playlist.
-- ------------------------------------------------------------
CREATE TABLE `galleries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `transition_default`     VARCHAR(20)       NOT NULL DEFAULT 'cut', -- transizione di default dei media della galleria
  `transition_duration_ms` SMALLINT UNSIGNED NOT NULL DEFAULT 600,   -- durata della transizione (0-10000 ms)
  `system_key` VARCHAR(40) NULL,                                     -- galleria di servizio (es. 'courtesy'): non eliminabile né rinominabile
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME     NULL,      -- cestino: NULL = attiva
  `deleted_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_galleries_system_key` (`system_key`),
  KEY `idx_galleries_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Media (file dentro una galleria)
-- I file NON vengono elaborati lato server: rotation e fit
-- sono metadati che l'app applica in fase di rendering.
-- ------------------------------------------------------------
CREATE TABLE `media` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `gallery_id`       INT UNSIGNED  NOT NULL,
  `type`             ENUM('image','video') NOT NULL,
  `filename`         VARCHAR(255)  NOT NULL,           -- nome file su disco in uploads/ (generato, univoco)
  `original_name`    VARCHAR(255)  NOT NULL,           -- nome file originale, solo per il CMS
  `mime_type`        VARCHAR(100)  NOT NULL,
  `filesize`         BIGINT UNSIGNED NOT NULL,         -- byte, usato dall'app per validare il download
  `checksum`         CHAR(40)      NOT NULL,           -- sha1 del file, per la cache dell'app
  `rotation`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 | 90 | 180 | 270
  `fit`              ENUM('contain','cover') NOT NULL DEFAULT 'contain', -- cover = crop a pieno schermo
  `duration_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 10, -- durata a schermo delle immagini (i video usano la propria)
  `transition_in`    VARCHAR(20)   NULL,                    -- transizione di ENTRATA; NULL = default della galleria
  `transition_out`   VARCHAR(20)   NULL,                    -- transizione di USCITA; NULL = default della galleria
  `muted`            TINYINT(1)    NOT NULL DEFAULT 1,      -- solo video: 1 = muto (default vetrina), 0 = con audio
  `sort_order`       INT UNSIGNED  NOT NULL DEFAULT 0, -- posizione nel drag & drop
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME      NULL,               -- cestino: NULL = attivo
  `deleted_by`       INT UNSIGNED  NULL,
  PRIMARY KEY (`id`),
  KEY `idx_media_gallery_order` (`gallery_id`, `sort_order`),
  KEY `idx_media_deleted` (`deleted_at`),
  CONSTRAINT `fk_media_gallery`
    FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Assegnazione gallerie -> schermi (molti a molti)
-- Uno schermo può ricevere più gallerie: la playlist finale è
-- la concatenazione delle gallerie in sort_order.
-- ------------------------------------------------------------
CREATE TABLE `screen_galleries` (
  `screen_id`  INT UNSIGNED NOT NULL,
  `gallery_id` INT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`screen_id`, `gallery_id`),
  KEY `idx_sg_gallery` (`gallery_id`),
  CONSTRAINT `fk_sg_screen`
    FOREIGN KEY (`screen_id`) REFERENCES `screens` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sg_gallery`
    FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK differita: screens.idle_media_id -> media.id (vedi nota sopra)
ALTER TABLE `screens`
  ADD CONSTRAINT `fk_screens_idle_media`
  FOREIGN KEY (`idle_media_id`) REFERENCES `media` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- L'utente amministratore NON viene inserito qui: la password va
-- hashata con password_hash() di PHP, quindi la creazione avviene
-- dal browser al primo accesso al CMS (setup guidato, FASE 3).
