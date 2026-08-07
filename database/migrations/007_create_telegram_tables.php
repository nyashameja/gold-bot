<?php

declare(strict_types=1);

/**
 * Telegram delivery (docs/02 §8).
 */

use Paragon\Core\Database;

return static function (Database $db): void {
    // Subscription flags are separate so operational alerts can go to a private
    // ops chat while signals go to a subscriber channel — and so a broken queue
    // does not announce itself to customers.
    $db->run(
        "CREATE TABLE IF NOT EXISTS telegram_chats (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id             VARCHAR(40)     NOT NULL,
            type                VARCHAR(20)     NOT NULL DEFAULT 'private',
            title               VARCHAR(150)    NULL,
            is_active           TINYINT(1)      NOT NULL DEFAULT 1,
            receives_signals    TINYINT(1)      NOT NULL DEFAULT 1,
            receives_alerts     TINYINT(1)      NOT NULL DEFAULT 0,
            receives_summaries  TINYINT(1)      NOT NULL DEFAULT 1,
            created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_telegram_chats_chat (chat_id),
            KEY ix_telegram_chats_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Message wording changes far more often than message logic, so templates
    // keep copy edits out of deployments.
    $db->run(
        "CREATE TABLE IF NOT EXISTS telegram_templates (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(40)     NOT NULL,
            name       VARCHAR(100)    NOT NULL,
            body       TEXT            NOT NULL,
            parse_mode VARCHAR(20)     NOT NULL DEFAULT 'HTML',
            is_active  TINYINT(1)      NOT NULL DEFAULT 1,
            updated_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_telegram_templates_code (code),
            CONSTRAINT fk_telegram_templates_user
                FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // The outbox (ADR-07).
    //
    // Messages are enqueued in the SAME transaction as the signal that caused
    // them, so a rolled-back signal cannot leave an orphaned alert and a
    // committed one cannot fail to produce its message. A separate cron drains
    // the queue, which makes delivery at-least-once with dedupe rather than
    // best-effort-and-hope.
    $db->run(
        "CREATE TABLE IF NOT EXISTS telegram_messages (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id             VARCHAR(40)     NOT NULL,
            template_code       VARCHAR(40)     NOT NULL,
            -- Derived deterministically from what the message is ABOUT
            -- (e.g. signal:{uuid}:TP1), so a duplicate send is impossible even
            -- if the producer runs twice.
            idempotency_key     VARCHAR(120)    NOT NULL,
            payload             JSON            NULL,
            rendered_text       TEXT            NULL,
            parse_mode          VARCHAR(20)     NOT NULL DEFAULT 'HTML',
            -- System alerts get their own lane, so a message about a broken
            -- queue is not stuck behind the queue it is reporting on.
            priority            TINYINT UNSIGNED NOT NULL DEFAULT 5,
            status              VARCHAR(10)     NOT NULL DEFAULT 'PENDING',
            attempts            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            last_error          VARCHAR(255)    NULL,
            provider_message_id VARCHAR(40)     NULL,
            signal_id           BIGINT UNSIGNED NULL,
            available_at        DATETIME        NOT NULL,
            sent_at             DATETIME        NULL,
            created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_telegram_messages_idempotency (idempotency_key),
            -- The drainer's query: pending, due, highest priority first.
            KEY ix_telegram_messages_drain (status, available_at, priority),
            KEY ix_telegram_messages_signal (signal_id),
            CONSTRAINT fk_telegram_messages_signal
                FOREIGN KEY (signal_id) REFERENCES signals (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
