<?php

declare(strict_types=1);

/**
 * Roles and permissions (docs/02 §3).
 *
 * Three roles: administrator holds everything; analyst operates the trading
 * side but cannot manage users or edit settings; viewer is read-only.
 *
 * Permissions are granular from the start because widening a role later is a
 * data change, whereas splitting a coarse permission after the fact means
 * revisiting every check that used it.
 */

use Paragon\Core\Database;

return static function (Database $db): int {
    $affected = 0;

    $roles = [
        ['slug' => 'administrator', 'name' => 'Administrator', 'description' => 'Full access to every part of the platform.', 'is_system' => 1],
        ['slug' => 'analyst',       'name' => 'Analyst',       'description' => 'Operates signals and strategies; cannot manage users or settings.', 'is_system' => 1],
        ['slug' => 'viewer',        'name' => 'Viewer',        'description' => 'Read-only access to dashboards and signals.', 'is_system' => 1],
    ];

    foreach ($roles as $role) {
        $affected += $db->upsert('roles', $role, ['name', 'description', 'is_system']);
    }

    $permissions = [
        // group => [slug => name]
        'signals' => [
            'signals.view'   => 'View signals',
            'signals.cancel' => 'Cancel an active signal',
            'signals.export' => 'Export signal history',
        ],
        'strategies' => [
            'strategies.view' => 'View strategies and their configuration',
            'strategies.edit' => 'Edit strategy configuration',
        ],
        'market' => [
            'market.view'     => 'View live market and charts',
            'market.backfill' => 'Trigger a historical data backfill',
        ],
        'calendar' => [
            'calendar.view' => 'View the economic calendar',
        ],
        'performance' => [
            'performance.view' => 'View performance analytics',
        ],
        'telegram' => [
            'telegram.view' => 'View Telegram configuration and queue',
            'telegram.send' => 'Send or requeue Telegram messages',
        ],
        'system' => [
            'health.view'   => 'View system health',
            'logs.view'     => 'View system logs',
            'api.view'      => 'View API usage',
            'settings.edit' => 'Edit application settings',
            'tasks.run'     => 'Trigger a scheduled task manually',
        ],
        'users' => [
            'users.view'   => 'View users',
            'users.manage' => 'Create, edit and deactivate users',
            'audit.view'   => 'View the audit log',
        ],
    ];

    $flat = [];

    foreach ($permissions as $group => $entries) {
        foreach ($entries as $slug => $name) {
            $affected += $db->upsert(
                'permissions',
                ['slug' => $slug, 'name' => $name, 'group' => $group],
                ['name', 'group']
            );

            $flat[] = $slug;
        }
    }

    // Role → permission grants.
    $grants = [
        'administrator' => $flat,
        'analyst' => [
            'signals.view', 'signals.cancel', 'signals.export',
            'strategies.view', 'strategies.edit',
            'market.view', 'market.backfill',
            'calendar.view', 'performance.view',
            'telegram.view', 'telegram.send',
            'health.view', 'api.view', 'tasks.run',
        ],
        'viewer' => [
            'signals.view', 'strategies.view', 'market.view',
            'calendar.view', 'performance.view', 'health.view',
        ],
    ];

    foreach ($grants as $roleSlug => $permissionSlugs) {
        $roleId = $db->scalar('SELECT id FROM roles WHERE slug = ?', [$roleSlug]);

        if ($roleId === null) {
            continue;
        }

        foreach ($permissionSlugs as $permissionSlug) {
            $permissionId = $db->scalar('SELECT id FROM permissions WHERE slug = ?', [$permissionSlug]);

            if ($permissionId === null) {
                continue;
            }

            // INSERT IGNORE rather than upsert: the row is the whole grant, so
            // there is nothing to update when it already exists.
            $affected += $db->run(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [(int) $roleId, (int) $permissionId]
            );
        }
    }

    return $affected;
};
