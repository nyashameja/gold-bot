<?php

declare(strict_types=1);

/**
 * Performance rollups (docs/02 §9).
 *
 * These rows are a PROJECTION, never a source of truth. Every figure here is
 * recomputable from `signals` alone, and the builder rebuilds any period from
 * scratch rather than adjusting a running total. That matters because signals
 * change after the fact — a late tick closes one, an operator cancels another —
 * and an incrementally maintained aggregate drifts from the records it claims
 * to summarise with nothing to detect the drift. Dropping this table entirely
 * costs a rebuild and no information.
 */

use GoldBot\Core\Database;

return static function (Database $db): void {
    $db->run(
        "CREATE TABLE IF NOT EXISTS performance_snapshots (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_type   VARCHAR(10)     NOT NULL,
            period_start  DATETIME        NOT NULL,
            period_end    DATETIME        NOT NULL,

            -- Dimension columns. NULL means 'all', so one table serves the
            -- overall figures and every breakdown the brief asks for without
            -- a table per dimension. The scope_key below makes the uniqueness
            -- of that combination enforceable.
            strategy_id   BIGINT UNSIGNED NULL,
            instrument_id BIGINT UNSIGNED NULL,
            session_code  VARCHAR(20)     NULL,
            timeframe_id  BIGINT UNSIGNED NULL,
            direction     VARCHAR(10)     NULL,

            -- MySQL treats NULLs as distinct in a UNIQUE index, so a natural
            -- key over nullable dimension columns would happily store the same
            -- overall snapshot a hundred times. This column is a deterministic
            -- rendering of the dimension tuple with a literal for 'all', which
            -- makes the constraint actually constrain.
            scope_key     VARCHAR(80)     NOT NULL,

            total_signals          INT UNSIGNED   NOT NULL DEFAULT 0,
            wins                   INT UNSIGNED   NOT NULL DEFAULT 0,
            losses                 INT UNSIGNED   NOT NULL DEFAULT 0,
            breakeven              INT UNSIGNED   NOT NULL DEFAULT 0,

            -- Every rate is NULLABLE. A win rate of 0% and a win rate that
            -- does not exist yet are different claims, and storing the second
            -- as the first tells the reader something false about a strategy
            -- that has simply not traded.
            win_rate               DECIMAL(6,2)   NULL,
            loss_rate              DECIMAL(6,2)   NULL,
            profit_factor          DECIMAL(10,2)  NULL,
            average_rr             DECIMAL(8,2)   NULL,
            average_win_r          DECIMAL(8,2)   NULL,
            average_loss_r         DECIMAL(8,2)   NULL,
            expectancy_r           DECIMAL(8,3)   NULL,
            average_score          DECIMAL(6,1)   NULL,

            gross_profit_r         DECIMAL(12,2)  NOT NULL DEFAULT 0,
            gross_loss_r           DECIMAL(12,2)  NOT NULL DEFAULT 0,
            total_r                DECIMAL(12,2)  NOT NULL DEFAULT 0,
            best_r                 DECIMAL(8,2)   NULL,
            worst_r                DECIMAL(8,2)   NULL,
            max_drawdown_r         DECIMAL(12,2)  NOT NULL DEFAULT 0,
            max_consecutive_wins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_consecutive_losses SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            computed_at   DATETIME(3)     NOT NULL,
            created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uq_performance_period (period_type, period_start, scope_key),
            KEY ix_performance_series (period_type, scope_key, period_start),
            KEY ix_performance_strategy (strategy_id, period_type, period_start),
            CONSTRAINT fk_performance_strategy
                FOREIGN KEY (strategy_id) REFERENCES strategies (id) ON DELETE CASCADE,
            CONSTRAINT fk_performance_instrument
                FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
