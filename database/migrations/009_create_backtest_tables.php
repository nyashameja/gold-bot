<?php

declare(strict_types=1);

/**
 * The backtesting harness (ADR-04).
 *
 * Backtest results live in their OWN tables, never in `signals`. That is the
 * central decision here and it is not about tidiness:
 *
 *   `signals` is the permanent record of what this system actually did. Every
 *   performance figure, every snapshot and every claim about the strategy is
 *   computed from it. A hypothetical run writing rows there would corrupt all
 *   of that — and a threshold sweep writes thousands of hypothetical runs.
 *   Once mixed, the two are not separable by inspection: a backtest trade and
 *   a live one look identical.
 *
 * The trades are still measured by the SAME PerformanceCalculator the live
 * dashboard uses, so a backtest and a live period are comparable. Shared
 * arithmetic, separate storage.
 */

use GoldBot\Core\Database;

return static function (Database $db): void {
    $db->run(
        "CREATE TABLE IF NOT EXISTS backtests (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid               BINARY(16)      NOT NULL,
            label              VARCHAR(120)    NULL,

            strategy_id        BIGINT UNSIGNED NOT NULL,
            -- The exact config the run used. Kept as a snapshot rather than a
            -- pointer alone: a run must stay interpretable after its config
            -- version is superseded, and 'which rules produced this?' is the
            -- first question anyone asks of a surprising result.
            strategy_config_id BIGINT UNSIGNED NULL,
            config_snapshot    JSON            NOT NULL,

            instrument_id      BIGINT UNSIGNED NOT NULL,
            timeframe_id       BIGINT UNSIGNED NOT NULL,
            period_from        DATETIME        NOT NULL,
            period_to          DATETIME        NOT NULL,

            -- What the run was allowed to do, recorded so a result can never
            -- be compared against one produced under different rules.
            min_score          DECIMAL(6,2)    NOT NULL,
            filters_enabled    TINYINT(1)      NOT NULL DEFAULT 1,
            news_filter        TINYINT(1)      NOT NULL DEFAULT 0,

            bars_evaluated     INT UNSIGNED    NOT NULL DEFAULT 0,
            signals_generated  INT UNSIGNED    NOT NULL DEFAULT 0,
            trades_closed      INT UNSIGNED    NOT NULL DEFAULT 0,
            still_open         INT UNSIGNED    NOT NULL DEFAULT 0,

            -- Denormalised headline metrics, so the list page does not have to
            -- re-measure every run to draw a table.
            wins               INT UNSIGNED    NOT NULL DEFAULT 0,
            losses             INT UNSIGNED    NOT NULL DEFAULT 0,
            breakeven          INT UNSIGNED    NOT NULL DEFAULT 0,
            win_rate           DECIMAL(6,2)    NULL,
            profit_factor      DECIMAL(10,2)   NULL,
            expectancy_r       DECIMAL(8,3)    NULL,
            total_r            DECIMAL(12,2)   NOT NULL DEFAULT 0,
            max_drawdown_r     DECIMAL(12,2)   NOT NULL DEFAULT 0,

            duration_ms        INT UNSIGNED    NULL,
            notes              VARCHAR(500)    NULL,
            created_by         BIGINT UNSIGNED NULL,
            created_at         DATETIME(3)     NOT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY uq_backtests_uuid (uuid),
            KEY ix_backtests_strategy (strategy_id, created_at),
            CONSTRAINT fk_backtests_strategy
                FOREIGN KEY (strategy_id) REFERENCES strategies (id) ON DELETE CASCADE,
            CONSTRAINT fk_backtests_user
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS backtest_trades (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            backtest_id    BIGINT UNSIGNED NOT NULL,

            direction      VARCHAR(10)     NOT NULL,
            score          DECIMAL(6,2)    NOT NULL,
            entry_price    DECIMAL(14,5)   NOT NULL,
            stop_loss      DECIMAL(14,5)   NOT NULL,
            risk_distance  DECIMAL(14,5)   NOT NULL,
            risk_reward    DECIMAL(8,2)    NULL,

            signalled_at   DATETIME        NOT NULL,
            activated_at   DATETIME        NULL,
            closed_at      DATETIME        NULL,
            -- ACTIVE and PENDING appear here: a run that ends mid-trade must
            -- say so rather than quietly closing the position at the last
            -- price, which would flatter any strategy holding a winner.
            outcome        VARCHAR(20)     NOT NULL,
            exit_price     DECIMAL(14,5)   NULL,
            realised_r     DECIMAL(8,3)    NULL,
            targets_hit    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            bars_held      INT UNSIGNED    NULL,
            session_code   VARCHAR(20)     NULL,

            PRIMARY KEY (id),
            KEY ix_backtest_trades_run (backtest_id, signalled_at),
            KEY ix_backtest_trades_score (backtest_id, score),
            CONSTRAINT fk_backtest_trades_run
                FOREIGN KEY (backtest_id) REFERENCES backtests (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
