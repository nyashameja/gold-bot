<?php

declare(strict_types=1);

/**
 * Market data (docs/02 §5).
 */

use GoldBot\Core\Database;

return static function (Database $db): void {
    // The highest-volume table. No uuid column by deliberate choice (ADR-10):
    // a random primary key on an append-heavy time series scatters inserts
    // across the B-tree instead of appending to the rightmost page, which
    // hurts exactly the range scans the strategy engine performs constantly.
    $db->run(
        "CREATE TABLE IF NOT EXISTS candles (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instrument_id BIGINT UNSIGNED NOT NULL,
            timeframe_id  BIGINT UNSIGNED NOT NULL,
            open_time     DATETIME        NOT NULL,
            close_time    DATETIME        NOT NULL,
            open          DECIMAL(14,5)   NOT NULL,
            high          DECIMAL(14,5)   NOT NULL,
            low           DECIMAL(14,5)   NOT NULL,
            close         DECIMAL(14,5)   NOT NULL,
            volume        DECIMAL(20,5)   NOT NULL DEFAULT 0,
            is_closed     TINYINT(1)      NOT NULL DEFAULT 0,
            source        VARCHAR(30)     NOT NULL DEFAULT 'TWELVE_DATA',
            created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            -- The linchpin: makes ingest idempotent via ON DUPLICATE KEY UPDATE,
            -- so a retried or overlapping fetch cannot duplicate a bar.
            UNIQUE KEY uq_candles_series (instrument_id, timeframe_id, open_time),
            -- Serves the dominant query: 'last N closed candles'.
            KEY ix_candles_lookup (instrument_id, timeframe_id, is_closed, open_time),
            CONSTRAINT fk_candles_instrument
                FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE,
            CONSTRAINT fk_candles_timeframe
                FOREIGN KEY (timeframe_id) REFERENCES timeframes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Wide rather than long (ADR-13): the indicator set is small and stable,
    // and both the dashboard and the strategy engine read many indicators for
    // one candle at once — one row read instead of a pivot over N rows.
    //
    // instrument_id, timeframe_id and open_time are denormalised from candles
    // so an indicator window loads without a join. Bounded and safe: all three
    // are immutable for the life of a candle.
    $db->run(
        "CREATE TABLE IF NOT EXISTS candle_indicators (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            candle_id      BIGINT UNSIGNED NOT NULL,
            instrument_id  BIGINT UNSIGNED NOT NULL,
            timeframe_id   BIGINT UNSIGNED NOT NULL,
            open_time      DATETIME        NOT NULL,
            ema_50         DECIMAL(14,5)   NULL,
            ema_200        DECIMAL(14,5)   NULL,
            rsi_14         DECIMAL(8,4)    NULL,
            atr_14         DECIMAL(14,5)   NULL,
            macd           DECIMAL(14,5)   NULL,
            macd_signal    DECIMAL(14,5)   NULL,
            macd_histogram DECIMAL(14,5)   NULL,
            bb_upper       DECIMAL(14,5)   NULL,
            bb_middle      DECIMAL(14,5)   NULL,
            bb_lower       DECIMAL(14,5)   NULL,
            volume_sma_20  DECIMAL(20,5)   NULL,
            computed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_candle_indicators_candle (candle_id),
            KEY ix_candle_indicators_series (instrument_id, timeframe_id, open_time),
            CONSTRAINT fk_candle_indicators_candle
                FOREIGN KEY (candle_id) REFERENCES candles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // captured_at and provider_time are both stored so the dashboard can show
    // genuine data age rather than the age of our own write (docs/01 §8).
    $db->run(
        "CREATE TABLE IF NOT EXISTS price_snapshots (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instrument_id     BIGINT UNSIGNED NOT NULL,
            price             DECIMAL(14,5)   NOT NULL,
            bid               DECIMAL(14,5)   NULL,
            ask               DECIMAL(14,5)   NULL,
            spread            DECIMAL(14,5)   NULL,
            day_high          DECIMAL(14,5)   NULL,
            day_low           DECIMAL(14,5)   NULL,
            change_absolute   DECIMAL(14,5)   NULL,
            change_percent    DECIMAL(10,4)   NULL,
            provider_time     DATETIME        NULL,
            captured_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_price_snapshots_recent (instrument_id, captured_at),
            CONSTRAINT fk_price_snapshots_instrument
                FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS market_structure_points (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instrument_id  BIGINT UNSIGNED NOT NULL,
            timeframe_id   BIGINT UNSIGNED NOT NULL,
            candle_id      BIGINT UNSIGNED NULL,
            type           VARCHAR(20)     NOT NULL,
            price          DECIMAL(14,5)   NOT NULL,
            direction      VARCHAR(10)     NULL,
            strength       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            occurred_at    DATETIME        NOT NULL,
            detected_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            invalidated_at TIMESTAMP       NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_structure_point (instrument_id, timeframe_id, type, occurred_at),
            KEY ix_structure_active (instrument_id, timeframe_id, invalidated_at, occurred_at),
            CONSTRAINT fk_structure_candle
                FOREIGN KEY (candle_id) REFERENCES candles (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Zones need two prices; single levels store the same value in both, which
    // keeps one table and one query path instead of two near-identical tables.
    $db->run(
        "CREATE TABLE IF NOT EXISTS market_levels (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instrument_id  BIGINT UNSIGNED NOT NULL,
            timeframe_id   BIGINT UNSIGNED NULL,
            type           VARCHAR(20)     NOT NULL,
            price_from     DECIMAL(14,5)   NOT NULL,
            price_to       DECIMAL(14,5)   NOT NULL,
            strength       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            touch_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active      TINYINT(1)      NOT NULL DEFAULT 1,
            formed_at      DATETIME        NOT NULL,
            invalidated_at TIMESTAMP       NULL DEFAULT NULL,
            created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_market_levels_active (instrument_id, is_active, type),
            CONSTRAINT fk_market_levels_instrument
                FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // What makes 'only analyse new candles' a structural property rather than
    // a convention someone has to remember. Each stage advances independently,
    // so a failure in one does not force another to recompute, and any stage
    // can be replayed by rewinding a single row.
    $db->run(
        "CREATE TABLE IF NOT EXISTS ingest_watermarks (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instrument_id  BIGINT UNSIGNED NOT NULL,
            timeframe_id   BIGINT UNSIGNED NOT NULL,
            stage          VARCHAR(20)     NOT NULL,
            last_open_time DATETIME        NULL,
            last_candle_id BIGINT UNSIGNED NULL,
            updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_watermark (instrument_id, timeframe_id, stage),
            CONSTRAINT fk_watermark_instrument
                FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE,
            CONSTRAINT fk_watermark_timeframe
                FOREIGN KEY (timeframe_id) REFERENCES timeframes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
