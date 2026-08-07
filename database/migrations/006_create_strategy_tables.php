<?php

declare(strict_types=1);

/**
 * Strategies and signals (docs/02 §7).
 */

use Paragon\Core\Database;

return static function (Database $db): void {
    // handler_class maps a row to its PHP implementation, so registering a
    // strategy is one row plus a container binding.
    $db->run(
        "CREATE TABLE IF NOT EXISTS strategies (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code        VARCHAR(30)     NOT NULL,
            name        VARCHAR(100)    NOT NULL,
            description VARCHAR(255)    NULL,
            class_name  VARCHAR(180)    NOT NULL,
            is_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at  TIMESTAMP       NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_strategies_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // IMMUTABLE (ADR-06). Editing a strategy writes a new version and moves the
    // active pointer; rows are never updated in place. Without this, tuning the
    // weights in March silently rewrites what February's signals were produced
    // by, and the performance history becomes fiction.
    $db->run(
        "CREATE TABLE IF NOT EXISTS strategy_configs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            strategy_id  BIGINT UNSIGNED NOT NULL,
            version      INT UNSIGNED    NOT NULL,
            config       JSON            NOT NULL,
            notes        VARCHAR(255)    NULL,
            is_active    TINYINT(1)      NOT NULL DEFAULT 0,
            created_by   BIGINT UNSIGNED NULL,
            created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            activated_at TIMESTAMP       NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_strategy_configs_version (strategy_id, version),
            KEY ix_strategy_configs_active (strategy_id, is_active),
            CONSTRAINT fk_strategy_configs_strategy
                FOREIGN KEY (strategy_id) REFERENCES strategies (id) ON DELETE CASCADE,
            CONSTRAINT fk_strategy_configs_user
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Written on EVERY evaluation, not only when a signal fires (docs/02 §7).
    // Three reasons: it answers "why did nothing fire today?", it makes
    // threshold tuning empirical by exposing the distribution of near-misses,
    // and its `features` column accumulates the labelled dataset any future ML
    // work needs. None of it can be backfilled later.
    $db->run(
        "CREATE TABLE IF NOT EXISTS strategy_runs (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            strategy_id        BIGINT UNSIGNED NOT NULL,
            strategy_config_id BIGINT UNSIGNED NOT NULL,
            instrument_id      BIGINT UNSIGNED NOT NULL,
            timeframe_id       BIGINT UNSIGNED NOT NULL,
            candle_id          BIGINT UNSIGNED NULL,
            evaluated_at       DATETIME(3)     NOT NULL,
            candle_open_time   DATETIME        NOT NULL,
            direction          VARCHAR(10)     NULL,
            score              DECIMAL(6,2)    NOT NULL DEFAULT 0,
            passed             TINYINT(1)      NOT NULL DEFAULT 0,
            rejection_reason   VARCHAR(120)    NULL,
            features           JSON            NULL,
            duration_ms        INT UNSIGNED    NULL,
            PRIMARY KEY (id),
            -- One evaluation per strategy per candle: makes the engine safely
            -- re-runnable without inflating the dataset with duplicates.
            UNIQUE KEY uq_strategy_runs_candle (strategy_id, instrument_id, timeframe_id, candle_open_time),
            KEY ix_strategy_runs_recent (strategy_id, evaluated_at),
            KEY ix_strategy_runs_passed (passed, evaluated_at),
            CONSTRAINT fk_strategy_runs_strategy
                FOREIGN KEY (strategy_id) REFERENCES strategies (id) ON DELETE CASCADE,
            CONSTRAINT fk_strategy_runs_config
                FOREIGN KEY (strategy_config_id) REFERENCES strategy_configs (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // `state` is a read-optimised projection; signal_events is the history and
    // the source of truth (ADR-05). realised_r is the currency of all
    // performance reporting — percentages mislead when position sizes differ.
    $db->run(
        "CREATE TABLE IF NOT EXISTS signals (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid               BINARY(16)      NOT NULL,
            strategy_id        BIGINT UNSIGNED NOT NULL,
            strategy_config_id BIGINT UNSIGNED NOT NULL,
            strategy_run_id    BIGINT UNSIGNED NULL,
            instrument_id      BIGINT UNSIGNED NOT NULL,
            timeframe_id       BIGINT UNSIGNED NOT NULL,
            direction          VARCHAR(10)     NOT NULL,
            state              VARCHAR(20)     NOT NULL DEFAULT 'PENDING',
            score              DECIMAL(6,2)    NOT NULL DEFAULT 0,
            entry_price        DECIMAL(14,5)   NOT NULL,
            stop_loss          DECIMAL(14,5)   NOT NULL,
            risk_reward        DECIMAL(8,2)    NULL,
            risk_distance      DECIMAL(14,5)   NOT NULL,
            session_code       VARCHAR(20)     NULL,
            market_regime      VARCHAR(20)     NULL,
            generated_at       DATETIME        NOT NULL,
            activated_at       DATETIME        NULL,
            closed_at          DATETIME        NULL,
            expires_at         DATETIME        NULL,
            close_reason       VARCHAR(30)     NULL,
            realised_r         DECIMAL(8,3)    NULL,
            created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_signals_uuid (uuid),
            KEY ix_signals_state (state, generated_at),
            KEY ix_signals_instrument (instrument_id, generated_at),
            KEY ix_signals_strategy (strategy_id, generated_at),
            CONSTRAINT fk_signals_strategy
                FOREIGN KEY (strategy_id) REFERENCES strategies (id) ON DELETE RESTRICT,
            -- RESTRICT, not CASCADE: a signal must remain attributable to the
            -- exact configuration that produced it, forever (ADR-06).
            CONSTRAINT fk_signals_config
                FOREIGN KEY (strategy_config_id) REFERENCES strategy_configs (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // A separate table because the number of targets is configurable; tp1/tp2/
    // tp3 columns would cap it at three forever.
    $db->run(
        "CREATE TABLE IF NOT EXISTS signal_targets (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            signal_id     BIGINT UNSIGNED NOT NULL,
            level         TINYINT UNSIGNED NOT NULL,
            price         DECIMAL(14,5)   NOT NULL,
            close_percent DECIMAL(5,2)    NOT NULL DEFAULT 100.00,
            r_multiple    DECIMAL(8,3)    NULL,
            hit_at        DATETIME        NULL,
            hit_price     DECIMAL(14,5)   NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_signal_targets_level (signal_id, level),
            CONSTRAINT fk_signal_targets_signal
                FOREIGN KEY (signal_id) REFERENCES signals (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Append-only, and the source of truth for a signal's history (ADR-05).
    // You cannot compute "average time to TP1" from a mutable status column.
    $db->run(
        "CREATE TABLE IF NOT EXISTS signal_events (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            signal_id      BIGINT UNSIGNED NOT NULL,
            event_type     VARCHAR(30)     NOT NULL,
            price_at_event DECIMAL(14,5)   NULL,
            notes          VARCHAR(255)    NULL,
            triggered_by   VARCHAR(10)     NOT NULL DEFAULT 'SYSTEM',
            user_id        BIGINT UNSIGNED NULL,
            occurred_at    DATETIME(3)     NOT NULL,
            PRIMARY KEY (id),
            KEY ix_signal_events_signal (signal_id, occurred_at),
            KEY ix_signal_events_type (event_type, occurred_at),
            CONSTRAINT fk_signal_events_signal
                FOREIGN KEY (signal_id) REFERENCES signals (id) ON DELETE CASCADE,
            CONSTRAINT fk_signal_events_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The per-pillar breakdown behind the score. This is what lets the 714 page
    // explain a signal rather than merely assert one — and what reveals that a
    // pillar is contributing nothing.
    $db->run(
        "CREATE TABLE IF NOT EXISTS signal_scores (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            signal_id      BIGINT UNSIGNED NOT NULL,
            pillar         VARCHAR(30)     NOT NULL,
            raw_score      DECIMAL(6,2)    NOT NULL,
            weight         DECIMAL(6,2)    NOT NULL,
            weighted_score DECIMAL(6,2)    NOT NULL,
            passed         TINYINT(1)      NOT NULL DEFAULT 1,
            detail         JSON            NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_signal_scores_pillar (signal_id, pillar),
            CONSTRAINT fk_signal_scores_signal
                FOREIGN KEY (signal_id) REFERENCES signals (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
