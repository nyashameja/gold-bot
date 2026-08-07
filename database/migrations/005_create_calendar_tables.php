<?php

declare(strict_types=1);

/**
 * Economic calendar (docs/02 §6).
 */

use Paragon\Core\Database;

return static function (Database $db): void {
    // Blackout windows live on the category, so "no signals within 30 minutes
    // of NFP" is configuration rather than code — and can differ per event
    // type, which it should: a rate decision warrants a wider window than
    // retail sales.
    $db->run(
        "CREATE TABLE IF NOT EXISTS event_categories (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code                    VARCHAR(30)     NOT NULL,
            name                    VARCHAR(100)    NOT NULL,
            default_impact          VARCHAR(10)     NOT NULL DEFAULT 'MEDIUM',
            blackout_minutes_before SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            blackout_minutes_after  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            match_patterns          JSON            NULL,
            is_active               TINYINT(1)      NOT NULL DEFAULT 1,
            created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_event_categories_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // This table is the ONLY archive of the calendar that will ever exist: the
    // upstream feed is a rolling week, not a queryable history (ADR-15). It is
    // never pruned, and history begins the day this ships.
    $db->run(
        "CREATE TABLE IF NOT EXISTS economic_events (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source              VARCHAR(30)     NOT NULL,
            -- Synthetic for sources that supply no id of their own (ADR-16).
            provider_event_id   CHAR(40)        NOT NULL,
            category_id         BIGINT UNSIGNED NULL,
            country             VARCHAR(60)     NULL,
            currency            VARCHAR(10)     NOT NULL,
            title               VARCHAR(200)    NOT NULL,
            impact              VARCHAR(10)     NOT NULL DEFAULT 'LOW',
            scheduled_at        DATETIME        NOT NULL,
            -- 'Tentative' or day-only events: the blackout window is widened
            -- rather than applied as though the minute were known.
            time_is_approximate TINYINT(1)      NOT NULL DEFAULT 0,
            actual              VARCHAR(40)     NULL,
            forecast            VARCHAR(40)     NULL,
            previous            VARCHAR(40)     NULL,
            revised_from        VARCHAR(40)     NULL,
            unit                VARCHAR(20)     NULL,
            detail_url          VARCHAR(255)    NULL,
            -- An unreleased event that stops appearing has been rescheduled or
            -- cancelled, and must be retired rather than suppressing signals
            -- forever (ADR-16).
            first_seen_at       DATETIME        NOT NULL,
            last_seen_at        DATETIME        NOT NULL,
            retired_at          DATETIME        NULL,
            created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_economic_events_provider (source, provider_event_id),
            -- The hottest read in the system: every strategy run asks whether a
            -- high-impact event is near now.
            KEY ix_economic_events_window (scheduled_at, impact, retired_at),
            KEY ix_economic_events_currency (currency, scheduled_at),
            KEY ix_economic_events_category (category_id, scheduled_at),
            CONSTRAINT fk_economic_events_category
                FOREIGN KEY (category_id) REFERENCES event_categories (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
