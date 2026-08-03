-- ============================================================
-- FRAME PARING STORE — Schema database
-- MySQL 5.7+ / MariaDB 10.3+ — importabile via phpMyAdmin
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Utenti amministratori del CMS
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)      NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL,           -- password_hash() PHP (bcrypt/argon2)
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
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
  `claim_token`      CHAR(64)      NULL,               -- token della URL dedicata (metodo manuale), azzerato dopo l'uso
  `pair_method`      ENUM('pin','qr','manual') NULL,   -- come è avvenuto il pairing (NULL finché pending)
  `auth_token`       CHAR(64)      NULL,               -- token segreto rilasciato al pairing (hex 256 bit)
  `status`           ENUM('pending','paired','revoked') NOT NULL DEFAULT 'pending',
  `device_info`      VARCHAR(255)  NULL,               -- modello/OS dichiarati dall'app
  `last_seen_at`     DATETIME      NULL,               -- ultimo polling ricevuto
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_screens_pairing_code` (`pairing_code`),
  UNIQUE KEY `uq_screens_claim_token` (`claim_token`),
  UNIQUE KEY `uq_screens_auth_token` (`auth_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Gallerie (playlist di media)
-- updated_at avanza a ogni modifica di contenuto/ordine:
-- l'app la usa per capire se scaricare di nuovo la playlist.
-- ------------------------------------------------------------
CREATE TABLE `galleries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
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
  `sort_order`       INT UNSIGNED  NOT NULL DEFAULT 0, -- posizione nel drag & drop
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_gallery_order` (`gallery_id`, `sort_order`),
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

SET FOREIGN_KEY_CHECKS = 1;

-- L'utente amministratore NON viene inserito qui: la password va
-- hashata con password_hash() di PHP, quindi la creazione avviene
-- dal browser al primo accesso al CMS (setup guidato, FASE 3).
