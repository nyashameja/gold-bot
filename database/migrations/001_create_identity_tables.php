<?php

declare(strict_types=1);

/**
 * Identity, access control and security (docs/02 §3).
 */

use Paragon\Core\Database;

return static function (Database $db): void {
    $db->run(
        "CREATE TABLE IF NOT EXISTS roles (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug        VARCHAR(50)     NOT NULL,
            name        VARCHAR(100)    NOT NULL,
            description VARCHAR(255)    NULL,
            is_system   TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_roles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS permissions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug        VARCHAR(100)    NOT NULL,
            name        VARCHAR(150)    NOT NULL,
            `group`     VARCHAR(50)     NOT NULL,
            created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_permissions_slug (slug),
            KEY ix_permissions_group (`group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS users (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid               BINARY(16)      NOT NULL,
            email              VARCHAR(190)    NOT NULL,
            password_hash      VARCHAR(255)    NOT NULL,
            name               VARCHAR(150)    NOT NULL,
            is_active          TINYINT(1)      NOT NULL DEFAULT 1,
            timezone           VARCHAR(64)     NOT NULL DEFAULT 'UTC',
            last_login_at      TIMESTAMP       NULL DEFAULT NULL,
            last_login_ip      VARBINARY(16)   NULL DEFAULT NULL,
            failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until       TIMESTAMP       NULL DEFAULT NULL,
            created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at         TIMESTAMP       NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_uuid (uuid),
            UNIQUE KEY uq_users_email (email),
            KEY ix_users_active (is_active, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id       BIGINT UNSIGNED NOT NULL,
            permission_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            KEY ix_role_permissions_permission (permission_id),
            CONSTRAINT fk_role_permissions_role
                FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
            CONSTRAINT fk_role_permissions_permission
                FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            KEY ix_user_roles_role (role_id),
            CONSTRAINT fk_user_roles_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_roles_role
                FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Database-backed sessions so they survive a PHP restart and an
    // administrator can revoke one (docs/02 §3).
    $db->run(
        "CREATE TABLE IF NOT EXISTS sessions (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id       VARCHAR(128)    NOT NULL,
            user_id          BIGINT UNSIGNED NULL DEFAULT NULL,
            ip_address       VARBINARY(16)   NULL DEFAULT NULL,
            user_agent       VARCHAR(255)    NULL DEFAULT NULL,
            payload          MEDIUMTEXT      NULL,
            last_activity_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at       TIMESTAMP       NOT NULL,
            created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sessions_session_id (session_id),
            KEY ix_sessions_user (user_id),
            KEY ix_sessions_expires (expires_at),
            CONSTRAINT fk_sessions_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // token_hash, never the token: a leaked table must not grant account
    // takeover (docs/02 §3).
    $db->run(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64)        NOT NULL,
            expires_at TIMESTAMP       NOT NULL,
            used_at    TIMESTAMP       NULL DEFAULT NULL,
            created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_resets_token (token_hash),
            KEY ix_password_resets_user (user_id, expires_at),
            CONSTRAINT fk_password_resets_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Not FK'd to users: failed attempts against a non-existent email must
    // still be recorded, since that is exactly what enumeration looks like.
    $db->run(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email        VARCHAR(190)    NOT NULL,
            ip_address   VARBINARY(16)   NULL DEFAULT NULL,
            succeeded    TINYINT(1)      NOT NULL DEFAULT 0,
            user_agent   VARCHAR(255)    NULL DEFAULT NULL,
            attempted_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_login_attempts_email (email, attempted_at),
            KEY ix_login_attempts_ip (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->run(
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid         BINARY(16)      NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(100)    NOT NULL,
            token_hash   CHAR(64)        NOT NULL,
            abilities    JSON            NULL,
            last_used_at TIMESTAMP       NULL DEFAULT NULL,
            expires_at   TIMESTAMP       NULL DEFAULT NULL,
            revoked_at   TIMESTAMP       NULL DEFAULT NULL,
            created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_tokens_uuid (uuid),
            UNIQUE KEY uq_api_tokens_hash (token_hash),
            KEY ix_api_tokens_user (user_id),
            CONSTRAINT fk_api_tokens_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Append-only. user_id is nullable because cron acts without a user, and
    // ON DELETE SET NULL so removing a user cannot erase their audit trail.
    $db->run(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
            action       VARCHAR(100)    NOT NULL,
            subject_type VARCHAR(100)    NULL DEFAULT NULL,
            subject_id   VARCHAR(64)     NULL DEFAULT NULL,
            `before`     JSON            NULL,
            `after`      JSON            NULL,
            ip_address   VARBINARY(16)   NULL DEFAULT NULL,
            user_agent   VARCHAR(255)    NULL DEFAULT NULL,
            created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_audit_logs_subject (subject_type, subject_id),
            KEY ix_audit_logs_user (user_id, created_at),
            KEY ix_audit_logs_action (action, created_at),
            CONSTRAINT fk_audit_logs_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
