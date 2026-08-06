<?php
/**
 * Primary navigation.
 *
 * Items are filtered by permission, so a viewer never sees a link that would
 * 403. That is a usability measure, not a security one — the Authorize
 * middleware is what actually enforces it (docs/01 §10).
 *
 * @var string $currentPath
 */

/** @var \GoldBot\Domain\Identity\User|null $authUser */
$user = $authUser ?? null;

$sections = [
    'Trading' => [
        ['label' => 'Overview',   'path' => '/',            'permission' => null,              'icon' => 'grid'],
        ['label' => 'Live Market', 'path' => '/market',     'permission' => 'market.view',     'icon' => 'chart'],
        ['label' => 'Signals',    'path' => '/signals',     'permission' => 'signals.view',    'icon' => 'signal'],
        ['label' => '714 Method', 'path' => '/method',      'permission' => 'strategies.view', 'icon' => 'target'],
    ],
    'Intelligence' => [
        ['label' => 'Calendar',    'path' => '/calendar',    'permission' => 'calendar.view',    'icon' => 'calendar'],
        ['label' => 'Performance', 'path' => '/performance', 'permission' => 'performance.view', 'icon' => 'trending'],
    ],
    'System' => [
        ['label' => 'Telegram',      'path' => '/telegram', 'permission' => 'telegram.view', 'icon' => 'send'],
        ['label' => 'API Usage',     'path' => '/api-usage', 'permission' => 'api.view',     'icon' => 'activity'],
        ['label' => 'System Health', 'path' => '/health',   'permission' => 'health.view',   'icon' => 'heart'],
        ['label' => 'Users',         'path' => '/users',    'permission' => 'users.view',    'icon' => 'users'],
        ['label' => 'Audit Log',     'path' => '/audit',    'permission' => 'audit.view',    'icon' => 'shield'],
        ['label' => 'Settings',      'path' => '/settings', 'permission' => 'settings.edit', 'icon' => 'cog'],
    ],
];

$icons = [
    'grid'     => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/>',
    'chart'    => '<path d="M3 3v18h18"/><path d="m7 14 3-4 3 3 5-7"/>',
    'signal'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20v-3"/>',
    'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    'trending' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/>',
    'send'     => '<path d="m22 2-7 20-4-9-9-4z"/>',
    'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    'heart'    => '<path d="M20.8 5.6a5.5 5.5 0 0 0-7.8 0L12 6.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l8.8 8.8 8.8-8.8a5.5 5.5 0 0 0 0-7.8z"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>',
    'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
];
?>
<div class="flex h-full flex-col">

    <!-- Brand -->
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-base-750 px-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gold-500 text-base-950">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true">
                <path d="M12 2 4 7v10l8 5 8-5V7z" opacity=".35"/>
                <path d="m12 6-4 2.5v5l4 2.5 4-2.5v-5z"/>
            </svg>
        </div>
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-ink-100">Gold Bot</div>
            <div class="truncate text-xs text-ink-500">XAU/USD Intelligence</div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Primary">
        <?php foreach ($sections as $heading => $items): ?>
            <?php
            $visible = array_filter(
                $items,
                static fn (array $i): bool => $i['permission'] === null || ($user?->can($i['permission']) ?? false)
            );
            ?>
            <?php if ($visible === []) { continue; } ?>

            <div class="mb-1 mt-5 px-3 text-xs font-medium uppercase tracking-wider text-ink-500 first:mt-0">
                <?= e($heading) ?>
            </div>

            <?php foreach ($visible as $item): ?>
                <?php $active = $currentPath === $item['path']; ?>
                <a href="<?= e($item['path']) ?>"
                   class="nav-link <?= $active ? 'nav-link-active' : '' ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px] shrink-0" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <?= $icons[$item['icon']] ?>
                    </svg>
                    <span class="truncate"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Account -->
    <?php if ($user !== null): ?>
        <div class="shrink-0 border-t border-base-750 p-3">
            <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                            bg-base-750 text-xs font-semibold text-gold-400">
                    <?= e($user->initials()) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium text-ink-100"><?= e($user->name) ?></div>
                    <div class="truncate text-xs text-ink-500"><?= e($user->roles[0] ?? 'user') ?></div>
                </div>
                <form method="post" action="/logout" class="shrink-0">
                    <?= $csrf->field() ?>
                    <button type="submit"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-500
                                   transition hover:bg-base-800 hover:text-bear-400"
                            aria-label="Sign out">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <path d="m16 17 5-5-5-5M21 12H9"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
