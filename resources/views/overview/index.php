<?php
/**
 * Overview.
 *
 * Phase 2 delivers the shell and the design system. Each tile below names the
 * phase that fills it — every one reads from MySQL (docs/01 §8), so none can
 * be populated before the tables it reads exist.
 *
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
$title = 'Overview';

$pending = [
    ['label' => 'Gold Price',   'detail' => 'Twelve Data ingest',      'phase' => 3],
    ['label' => 'Market Bias',  'detail' => 'Trend & structure',       'phase' => 4],
    ['label' => '714 Score',    'detail' => 'Strategy engine',         'phase' => 6],
    ['label' => 'Session',      'detail' => 'DST-aware resolver',      'phase' => 1, 'ready' => true],
    ['label' => 'Next Event',   'detail' => 'Economic calendar',       'phase' => 5],
    ['label' => 'Signal Status', 'detail' => 'Signal lifecycle',       'phase' => 7],
    ['label' => 'API Usage',    'detail' => 'Provider budget ledger',  'phase' => 3],
    ['label' => 'System Health', 'detail' => 'Component checks',       'phase' => 10],
];
?>

<div class="mb-6">
    <h2 class="text-lg font-semibold text-ink-100">
        Welcome back<?= $authUser !== null ? ', ' . e(explode(' ', $authUser->name)[0]) : '' ?>
    </h2>
    <p class="mt-1 text-sm text-ink-400">
        The platform shell is live. Widgets activate as their data sources are built.
    </p>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($pending as $tile): ?>
        <div class="card card-hover p-5">
            <div class="flex items-start justify-between gap-3">
                <span class="stat-label"><?= e($tile['label']) ?></span>
                <?php if (!empty($tile['ready'])): ?>
                    <span class="badge badge-gold">Ready</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Phase <?= e((string) $tile['phase']) ?></span>
                <?php endif; ?>
            </div>
            <div class="stat-value mt-3 text-ink-500">—</div>
            <div class="mt-1 text-xs text-ink-500"><?= e($tile['detail']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="card p-5 lg:col-span-2">
        <h3 class="text-sm font-semibold text-ink-100">Recent signals</h3>
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-base-800">
                <svg viewBox="0 0 24 24" class="h-5 w-5 text-ink-500" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 20V10M10 20V4M16 20v-7M22 20v-3"/>
                </svg>
            </div>
            <p class="text-sm text-ink-400">No signals yet</p>
            <p class="mt-1 max-w-xs text-xs text-ink-500">
                The signal engine arrives in Phase 6, once market data and indicators are in place.
            </p>
        </div>
    </div>

    <div class="card p-5">
        <h3 class="mb-4 text-sm font-semibold text-ink-100">Build progress</h3>
        <?php
        $phases = [
            ['n' => 0,  'name' => 'Foundations',        'done' => true],
            ['n' => 1,  'name' => 'Kernel & database',  'done' => true],
            ['n' => 2,  'name' => 'Auth, RBAC & shell', 'done' => true],
            ['n' => 3,  'name' => 'Market data',        'done' => false],
            ['n' => 5,  'name' => 'Economic calendar',  'done' => false],
            ['n' => 6,  'name' => 'Signal engine',      'done' => false],
        ];
        ?>
        <ul class="space-y-3">
            <?php foreach ($phases as $phase): ?>
                <li class="flex items-center gap-3">
                    <span class="dot <?= $phase['done'] ? 'bg-bull-500' : 'bg-base-600' ?>" aria-hidden="true"></span>
                    <span class="flex-1 text-sm <?= $phase['done'] ? 'text-ink-300' : 'text-ink-500' ?>">
                        <?= e($phase['name']) ?>
                    </span>
                    <span class="text-xs <?= $phase['done'] ? 'text-bull-400' : 'text-ink-500' ?>">
                        <?= $phase['done'] ? 'Done' : 'Phase ' . e((string) $phase['n']) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
