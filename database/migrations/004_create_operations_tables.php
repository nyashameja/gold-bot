<?php

declare(strict_types=1);

/**
 * Operations: API budget, the scheduler, health and logs (docs/02 §9).
 */

use GoldBot\Core\Database;

return static function (Database $db): void {
    $db->run(
        "CREATE TABLE IF NOT EXISTS api_providers (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code              VARCHAR(30)     NOT NULL,
            name              VARCHAR(100)    NOT NULL,
            base_url          VARCHAR(255)    NOT NULL,
            daily_limit       INT UNSIGNED    NULL,
            per_minute_limit  INT UNSIGNED    NULL,
            is_active         TINYINT(1)      NOT NULL DEFAULT 1,
            created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_providers_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Powers both the API Usage page and the budget gate. The index carries
    // requested_at because every budget check is a rolling-window count.
    $db->run(
        "CREATE TABLE IF NOT EXISTS api_usage_log (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id      BIGINT UNSIGNED NOT NULL,
            endpoint         VARCHAR(120)    NOT NULL,
            method           VARCHAR(10)     NOT NULL DEFAULT 'GET',
            http_status      SMALLINT UNSIGNED NULL,
            succeeded        TINYINT(1)      NOT NULL DEFAULT 0,
            response_time_ms INT UNSIGNED    NULL,
            error_message    VARCHAR(255)    NULL,
            credits_used     INT UNSIGNED    NOT NULL DEFAULT 1,
            requested_at     DATETIME(3)     NOT NULL,
            PRIMARY KEY (id),
            KEY ix_api_usage_window (provider_id, requested_at),
            KEY ix_api_usage_failures (provider_id, succeeded, requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The schedule as data (ADR-08): changing a cadence is a settings edit,
    // not a cPanel cron change. last_success_at measured against cadence is
    // what detects a task that stopped silently — the failure mode that
    // produces no errors at all.
    $db->run(
        "CREATE TABLE IF NOT EXISTS scheduled_tasks (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code                  VARCHAR(50)     NOT NULL,
            name                  VARCHAR(100)    NOT NULL,
            handler_class         VARCHAR(180)    NOT NULL,
            cadence_minutes       INT UNSIGNED    NOT NULL DEFAULT 1,
            offset_seconds        INT UNSIGNED    NOT NULL DEFAULT 0,
            is_enabled            TINYINT(1)      NOT NULL DEFAULT 1,
            timeout_seconds       INT UNSIGNED    NOT NULL DEFAULT 300,
            lock_timeout_seconds  INT UNSIGNED    NOT NULL DEFAULT 0,
            last_run_at           DATETIME        NULL,
            last_success_at       DATETIME        NULL,
            next_due_at           DATETIME        NULL,
            consecutive_failures  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scheduled_tasks_code (code),
            KEY ix_scheduled_tasks_due (is_enabled, next_due_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The distinct skip statuses matter operationally: SKIPPED_LOCKED is
    // healthy, SKIPPED_BUDGET is a warning, FAILED is an error. Collapsing
    // them into "didn't run" discards what is needed to respond.
    $db->run(
        "CREATE TABLE IF NOT EXISTS task_runs (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id         BIGINT UNSIGNED NOT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'RUNNING',
            started_at      DATETIME(3)     NOT NULL,
            finished_at     DATETIME(3)     NULL,
            duration_ms     INT UNSIGNED    NULL,
            items_processed INT UNSIGNED    NOT NULL DEFAULT 0,
            output          VARCHAR(500)    NULL,
            error_message   TEXT            NULL,
            PRIMARY KEY (id),
            KEY ix_task_runs_task (task_id, started_at),
            KEY ix_task_runs_status (status, started_at),
            CONSTRAINT fk_task_runs_task
                FOREIGN KEY (task_id) REFERENCES scheduled_tasks (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS health_checks (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            component   VARCHAR(50)     NOT NULL,
            status      VARCHAR(10)     NOT NULL,
            message     VARCHAR(255)    NULL,
            metrics     JSON            NULL,
            duration_ms INT UNSIGNED    NULL,
            checked_at  DATETIME(3)     NOT NULL,
            PRIMARY KEY (id),
            KEY ix_health_checks_latest (component, checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The UI-surfaced subset; full-fidelity logs stay in rotated files.
    $db->run(
        "CREATE TABLE IF NOT EXISTS system_logs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level      VARCHAR(20)     NOT NULL,
            channel    VARCHAR(50)     NOT NULL DEFAULT 'app',
            event      VARCHAR(80)     NULL,
            message    TEXT            NOT NULL,
            context    JSON            NULL,
            user_id    BIGINT UNSIGNED NULL,
            ip_address VARBINARY(16)   NULL,
            created_at DATETIME(3)     NOT NULL,
            PRIMARY KEY (id),
            KEY ix_system_logs_level (level, created_at),
            KEY ix_system_logs_channel (channel, created_at),
            KEY ix_system_logs_event (event, created_at),
            CONSTRAINT fk_system_logs_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
