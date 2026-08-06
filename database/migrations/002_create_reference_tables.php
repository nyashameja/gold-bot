<?php

declare(strict_types=1);

/**
 * Reference data: instruments, timeframes, sessions and settings (docs/02 §4).
 */

use GoldBot\Core\Database;

return static function (Database $db): void {
    // instrument_id is a first-class dimension on every market table from day
    // one, so adding a second instrument in V2 needs no restructuring.
    $db->run(
        "CREATE TABLE IF NOT EXISTS instruments (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol          VARCHAR(20)     NOT NULL,
            provider_symbol VARCHAR(30)     NOT NULL,
            name            VARCHAR(100)    NOT NULL,
            asset_class     VARCHAR(20)     NOT NULL DEFAULT 'METAL',
            price_precision TINYINT UNSIGNED NOT NULL DEFAULT 2,
            pip_size        DECIMAL(14,5)   NOT NULL DEFAULT 0.10000,
            contract_size   DECIMAL(14,2)   NOT NULL DEFAULT 100.00,
            is_active       TINYINT(1)      NOT NULL DEFAULT 1,
            created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at      TIMESTAMP       NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_instruments_symbol (symbol),
            KEY ix_instruments_active (is_active, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // `minutes` lets candle boundaries be computed arithmetically rather than
    // with a per-timeframe branch. M5 is seeded inactive, ready for V2.
    $db->run(
        "CREATE TABLE IF NOT EXISTS timeframes (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code              VARCHAR(10)     NOT NULL,
            minutes           INT UNSIGNED    NOT NULL,
            provider_interval VARCHAR(20)     NOT NULL,
            sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active         TINYINT(1)      NOT NULL DEFAULT 1,
            created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_timeframes_code (code),
            KEY ix_timeframes_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Local time plus IANA zone, never a fixed UTC offset: London and New York
    // change to and from DST on different dates, and hardcoded offsets go
    // silently wrong for several weeks a year (docs/02 §4).
    $db->run(
        "CREATE TABLE IF NOT EXISTS market_sessions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(20)     NOT NULL,
            name       VARCHAR(50)     NOT NULL,
            open_time  TIME            NOT NULL,
            close_time TIME            NOT NULL,
            timezone   VARCHAR(64)     NOT NULL,
            is_active  TINYINT(1)      NOT NULL DEFAULT 1,
            created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_market_sessions_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Typed so reads cast correctly instead of every caller re-parsing.
    // is_secret masks the value in the UI and redacts it from audit entries.
    $db->run(
        "CREATE TABLE IF NOT EXISTS settings (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key`       VARCHAR(100)    NOT NULL,
            `value`     TEXT            NULL,
            `type`      VARCHAR(10)     NOT NULL DEFAULT 'string',
            `group`     VARCHAR(50)     NOT NULL DEFAULT 'general',
            label       VARCHAR(150)    NOT NULL,
            description VARCHAR(255)    NULL,
            is_secret   TINYINT(1)      NOT NULL DEFAULT 0,
            updated_by  BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_settings_key (`key`),
            KEY ix_settings_group (`group`),
            CONSTRAINT fk_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
