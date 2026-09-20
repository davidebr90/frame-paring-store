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
  `language`         CHAR(5)       NULL,               -- lingua dell'interfaccia; NULL = predefinita del sistema
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1, -- disattivazione senza cancellare la storia
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME         NULL,
  `deleted_at`    DATETIME         NULL,   -- cestino: NULL = utente attivo
  `deleted_by`    INT UNSIGNED     NULL,
  -- Verifica in due passaggi (TOTP). Il segreto resta in chiaro: a
  -- differenza dei token sotto, va riletto per calcolare i codici, non solo
  -- confrontato. Si scrive su riga solo a conferma avvenuta (un primo
  -- codice corretto durante l'attivazione): niente segreti "a metà" salvati
  -- per un'attivazione mai completata.
  `totp_secret`    VARCHAR(64)  NULL,
  `totp_enabled`   TINYINT(1)   NOT NULL DEFAULT 0,
  -- Reset via email: stesso schema token/impronta di auth_token/poll_token
  -- (screens), con scadenza e uso singolo.
  `totp_reset_token`       CHAR(64)  NULL,
  `totp_reset_expires_at`  DATETIME  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tentativi di accesso falliti, per il blocco anti forza bruta.
-- La chiave lega username e indirizzo: bloccare il solo username
-- permetterebbe a chiunque di chiudere fuori l'amministratore vero.
-- ------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `attempt_key` CHAR(40)     NOT NULL,  -- sha1(username|ip)
  `attempts`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`attempt_key`)
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
  `rotation`         SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- 0|90|180|270: di quanto è girato il pannello
                                                            -- fisico rispetto al segnale (in senso orario).
                                                            -- Non tocca gallerie/video, che hanno una propria
                                                            -- rotazione per file: serve solo a far atterrare la
                                                            -- sovraimpressione sul lato giusto dello schermo
                                                            -- ruotato, in "alto/basso/sinistra/destra" come li
                                                            -- vede davvero chi guarda il pannello montato.
  `schedule_json`    TEXT          NULL,               -- fasce orarie attive [{"from":"09:00","to":"20:00"},...]; NULL = sempre attivo
  `idle_mode`        ENUM('black','image') NOT NULL DEFAULT 'black', -- fuori fascia: nero (spento apparente) o immagine di cortesia
  `idle_media_id`    INT UNSIGNED  NULL,               -- immagine di cortesia (un media immagine)
  `overlay_json`     TEXT          NULL,               -- banner personalizzato di questo schermo; NULL = eredita le impostazioni generali
  `last_seen_at`     DATETIME      NULL,               -- ultimo polling ricevuto
  `restart_count`    INT UNSIGNED  NOT NULL DEFAULT 0, -- avvii veri del processo app (non il polling)
  `last_restart_reason` VARCHAR(20) NULL,               -- 'boot' | 'watchdog' | 'manual'
  `last_restart_at`  DATETIME      NULL,
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
-- I file NON vengono elaborati lato server: fit è un metadato che
-- l'app applica in fase di rendering. `rotation` invece non è più
-- quella davvero usata in riproduzione: resta per compatibilità ma
-- la rotazione effettiva si calcola per schermo (vedi
-- screen_media_overrides e effective_item_rotation() in
-- core/screens.php) a partire da `width`/`height` e dal montaggio
-- di ciascuno schermo — la stessa foto può quindi apparire ruotata
-- in modo diverso su schermi diversi.
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
  -- Copia ridotta servita ai dispositivi al posto dell'originale (vedi
  -- core/renditions.php). L'originale resta intatto e continua a servire
  -- il CMS. rendition_at segna il tentativo: valorizzato con
  -- rendition_filename NULL significa "gia provato, non serve".
  `rendition_filename` VARCHAR(255)    NULL,
  `rendition_filesize` BIGINT UNSIGNED NULL,
  `rendition_checksum` CHAR(40)        NULL,
  `rendition_at`       DATETIME        NULL,
  `width`            INT UNSIGNED  NULL,               -- dimensioni naturali in pixel; solo immagini,
  `height`           INT UNSIGNED  NULL,               -- calcolate al caricamento (o al bisogno per i file precedenti)
  `rotation`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- legacy, non più letta in riproduzione (vedi sopra)
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

-- ------------------------------------------------------------
-- Rotazione di un media forzata per un singolo schermo
-- Di norma la rotazione si calcola da sola (orientamento della foto
-- confrontato con quello dello schermo, vedi media.width/height e
-- screens.rotation): questa tabella esiste solo per chi vuole
-- correggere a mano il risultato automatico su UNO schermo specifico,
-- senza toccare né il file né gli altri schermi che condividono la
-- stessa galleria.
-- ------------------------------------------------------------
CREATE TABLE `screen_media_overrides` (
  `screen_id` INT UNSIGNED NOT NULL,
  `media_id`  INT UNSIGNED NOT NULL,
  `rotation`  SMALLINT UNSIGNED NOT NULL,  -- 0|90|180|270; "torna automatico" cancella la riga, non la svuota
  PRIMARY KEY (`screen_id`, `media_id`),
  KEY `idx_smo_media` (`media_id`),
  CONSTRAINT `fk_smo_screen`
    FOREIGN KEY (`screen_id`) REFERENCES `screens` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_smo_media`
    FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Dirette in sovrapposizione
-- Livello temporaneo che si sovrappone alla riproduzione normale:
-- non modifica gallerie, fasce orarie né impostazioni degli schermi.
-- Al termine (durata scaduta o interruzione manuale) ogni schermo
-- riprende da solo la propria programmazione.
-- ------------------------------------------------------------
CREATE TABLE `live_overrides` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(100) NOT NULL,
  `source_type`  ENUM('media','external','gallery') NOT NULL, -- singolo file, flusso esterno o intera galleria
  `media_id`     INT UNSIGNED NULL,                 -- se source_type = 'media'
  `gallery_id`   INT UNSIGNED NULL,                 -- se source_type = 'gallery'
  `source_url`   VARCHAR(500) NULL,                 -- se source_type = 'external' (HLS/DASH/RTSP)
  `fit`          ENUM('contain','cover') NOT NULL DEFAULT 'contain',
  `rotation`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `muted`        TINYINT(1)   NOT NULL DEFAULT 0,
  `loop_content` TINYINT(1)   NOT NULL DEFAULT 1,   -- ripete il file fino al termine
  `started_at`   DATETIME     NOT NULL,             -- riferimento per la sincronizzazione fra schermi
  `ends_at`      DATETIME     NULL,                 -- NULL = fino a interruzione manuale
  `stopped_at`   DATETIME     NULL,                 -- valorizzato all'interruzione
  `created_by`   INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_live_active` (`stopped_at`, `ends_at`),
  CONSTRAINT `fk_live_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_live_gallery` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schermi coinvolti nella diretta (uno, alcuni o tutti)
-- Uno schermo per riga, con la possibilità di scostarsi dalle impostazioni
-- della diretta: lo stesso contenuto su un pannello orizzontale e su uno
-- verticale vuole spesso rotazione o adattamento diversi.
CREATE TABLE `live_override_screens` (
  `override_id` INT UNSIGNED NOT NULL,
  `screen_id`   INT UNSIGNED NOT NULL,
  `fit`         ENUM('contain','cover') NULL,  -- NULL = quello della diretta
  `rotation`    SMALLINT UNSIGNED       NULL,  -- NULL = quella della diretta
  PRIMARY KEY (`override_id`, `screen_id`),
  KEY `idx_los_screen` (`screen_id`),
  CONSTRAINT `fk_los_override` FOREIGN KEY (`override_id`) REFERENCES `live_overrides` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_los_screen` FOREIGN KEY (`screen_id`) REFERENCES `screens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `screen_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `screen_id`   INT UNSIGNED NOT NULL,
  `type`        VARCHAR(20)  NOT NULL,            -- 'registered' | 'paired' | 'restart'
                                                  -- 'gap' | 'config' | 'revoked'.
                                                  -- VARCHAR e non ENUM di proposito: aggiungere
                                                  -- un tipo non deve richiedere una migrazione
  `reason`      VARCHAR(20)  NULL,                -- solo 'restart': 'boot' | 'watchdog' | 'manual'
  `gap_seconds` INT UNSIGNED NULL,                -- solo 'gap': durata dell'assenza, in secondi
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- per 'gap' e' l'istante del RIENTRO
  PRIMARY KEY (`id`),
  KEY `idx_se_screen` (`screen_id`, `id`),
  CONSTRAINT `fk_se_screen` FOREIGN KEY (`screen_id`) REFERENCES `screens` (`id`) ON DELETE CASCADE
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
