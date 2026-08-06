<?php
/**
 * System Health.
 *
 * The checks are computed live on page load, not replayed from the
 * health_checks table. If the scheduler has stopped then so has the health
 * cron, and a page that only showed stored results would display the last
 * cheerful green row it managed to write before everything died. A dashboard
 * that cannot detect its own monitoring having stopped is decorative.
 *
 * @var array<string,mixed> $board
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
$canRun = $authUser?->can('tasks.run') ?? false;
$canSeeLogs = $authUser?->can('logs.view') ?? false;
?>

<div x-data="healthStatus" x-init="start" class="space-y-6">

    <!-- Overall -->
    <div class="card flex flex-wrap items-center justify-between gap-4 p-5">
        <div>
            <h2 class="text-lg font-semibold text-ink-100">System status</h2>
            <p class="mt-1 text-xs text-ink-500">
                Checked live at
                <time class="num" datetime="<?= e($board['checked_at']) ?>"><?= e($board['checked_at']) ?></time>
            </p>
        </div>
        <!--
          Bound as text and classes, never as markup: Alpine's CSP build
          prohibits x-html outright, and injecting a server-built HTML string
          would be the wrong shape here even if it did not.
        -->
        <span class="badge" :class="pillClass">
            <span class="dot" :class="dotClass" aria-hidden="true"></span>
            <span x-text="status"><?= e($board['overall']) ?></span>
        </span>
    </div>

    <!-- Checks -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($board['checks'] as $check): ?>
            <?php
            $border = match ($check['status']) {
                'CRITICAL' => 'border-bear-500/40',
                'WARNING'  => 'border-warn-400/40',
                default    => 'border-base-750',
            };
            ?>
            <div class="card <?= $border ?> p-5">
                <div class="flex items-start justify-between gap-2">
                    <span class="stat-label"><?= e($check['label']) ?></span>
                    <?= $this->partial('partials.status-pill', ['status' => $check['status']]) ?>
                </div>
                <p class="mt-3 text-sm text-ink-300"><?= e($check['message']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Scheduled tasks -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Scheduled tasks</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                A task that stops being invoked logs nothing and raises nothing — it just leaves its data
                getting quietly older. Measuring the last success against the cadence is the only way to
                see it.
            </p>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[860px] text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 font-medium">Task</th>
                        <th class="px-5 py-3 font-medium">Cadence</th>
                        <th class="px-5 py-3 font-medium">Last success</th>
                        <th class="px-5 py-3 font-medium">Last result</th>
                        <th class="px-5 py-3 text-right font-medium">Duration</th>
                        <?php if ($canRun): ?>
                            <th class="px-5 py-3 text-right font-medium"><span class="sr-only">Run</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($board['tasks'] as $task): ?>
                        <tr class="border-b border-base-800 last:border-0 <?= $task['enabled'] ? '' : 'opacity-50' ?>">
                            <td class="px-5 py-3">
                                <div class="text-ink-100"><?= e($task['name']) ?></div>
                                <div class="num text-xs text-ink-500"><?= e($task['code']) ?></div>
                            </td>
                            <td class="num px-5 py-3 text-xs text-ink-400">
                                <?= $task['enabled'] ? e('every ' . $task['cadence_minutes'] . 'm') : 'disabled' ?>
                            </td>
                            <td class="px-5 py-3">
                                <?= $this->partial('partials.data-age', ['age' => $task['age']]) ?>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($task['last_status'] === null): ?>
                                    <span class="text-xs text-ink-500">Never run</span>
                                <?php else: ?>
                                    <?= $this->partial('partials.status-pill', [
                                        'status' => $task['last_status'],
                                        'label'  => ucwords(strtolower(str_replace('_', ' ', $task['last_status']))),
                                    ]) ?>
                                    <?php if ($task['last_output'] !== null && $task['last_output'] !== ''): ?>
                                        <p class="mt-1 max-w-sm truncate text-xs text-ink-500"
                                           title="<?= e($task['last_output']) ?>"><?= e($task['last_output']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($task['consecutive_failures'] > 0): ?>
                                        <p class="mt-1 text-xs text-bear-400">
                                            <?= e((string) $task['consecutive_failures']) ?> consecutive failures
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="num px-5 py-3 text-right text-xs text-ink-500">
                                <?= $task['last_duration_ms'] === null ? '—' : e($task['last_duration_ms'] . 'ms') ?>
                            </td>
                            <?php if ($canRun): ?>
                                <td class="px-5 py-3 text-right">
                                    <form method="post" action="/health/tasks/<?= e($task['code']) ?>/run">
                                        <?= $csrf->field() ?>
                                        <button type="submit" class="btn btn-ghost !min-h-0 !px-3 !py-1.5 text-xs">
                                            Run now
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Reliability -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Reliability · 7 days</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Skips are shown separately: a lock skip is healthy, a budget skip is a warning.
                    Collapsing them into "did not run" discards what you need to respond.
                </p>
            </div>
            <div class="table-scroll">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-2.5 font-medium">Task</th>
                            <th class="px-5 py-2.5 text-right font-medium">Runs</th>
                            <th class="px-5 py-2.5 text-right font-medium">OK</th>
                            <th class="px-5 py-2.5 text-right font-medium">Failed</th>
                            <th class="px-5 py-2.5 text-right font-medium">Skipped</th>
                            <th class="px-5 py-2.5 text-right font-medium">Avg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($board['reliability'] as $row): ?>
                            <tr class="border-b border-base-800 last:border-0">
                                <td class="num px-5 py-2.5 text-xs text-ink-300"><?= e((string) $row['code']) ?></td>
                                <td class="num px-5 py-2.5 text-right text-ink-400"><?= e((string) $row['runs']) ?></td>
                                <td class="num px-5 py-2.5 text-right text-bull-400"><?= e((string) (int) $row['successes']) ?></td>
                                <td class="num px-5 py-2.5 text-right <?= (int) $row['failures'] > 0 ? 'text-bear-400' : 'text-ink-500' ?>">
                                    <?= e((string) (int) $row['failures']) ?>
                                </td>
                                <td class="num px-5 py-2.5 text-right text-ink-500"><?= e((string) (int) $row['skips']) ?></td>
                                <td class="num px-5 py-2.5 text-right text-ink-500">
                                    <?= $row['avg_duration_ms'] === null ? '—' : e((int) $row['avg_duration_ms'] . 'ms') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Runtime + storage -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Runtime</h3>
            </div>
            <dl class="divide-y divide-base-800">
                <?php
                $runtime = $board['runtime'];
                $facts = [
                    'PHP version'  => $runtime['php_version'],
                    'Server time'  => $runtime['server_time'],
                    'Timezone'     => $runtime['timezone'],
                    'Memory limit' => $runtime['memory_limit'],
                    'Peak memory'  => $runtime['memory_peak_mb'] . ' MB',
                    'Database size' => number_format($board['tables']['total_bytes'] / 1_048_576, 1) . ' MB',
                ];
                ?>
                <?php foreach ($facts as $label => $value): ?>
                    <div class="flex items-center justify-between gap-3 px-5 py-2.5">
                        <dt class="text-sm text-ink-500"><?= e($label) ?></dt>
                        <dd class="num text-sm text-ink-200"><?= e((string) $value) ?></dd>
                    </div>
                <?php endforeach; ?>
                <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                    <dt class="text-sm text-ink-500">Extensions</dt>
                    <dd class="flex flex-wrap justify-end gap-1.5">
                        <?php foreach ($runtime['extensions'] as $name => $loaded): ?>
                            <span class="badge <?= $loaded ? 'badge-bull' : 'badge-bear' ?>"><?= e((string) $name) ?></span>
                        <?php endforeach; ?>
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Table growth -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Table sizes</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                Row counts are InnoDB estimates from information_schema — accurate enough for a trend and
                far cheaper than a COUNT per table.
            </p>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[420px] text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-2.5 font-medium">Table</th>
                        <th class="px-5 py-2.5 text-right font-medium">Rows (est.)</th>
                        <th class="px-5 py-2.5 text-right font-medium">Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($board['tables']['rows'], 0, 12) as $table): ?>
                        <tr class="border-b border-base-800 last:border-0">
                            <td class="num px-5 py-2.5 text-xs text-ink-300"><?= e($table['table_name']) ?></td>
                            <td class="num px-5 py-2.5 text-right text-ink-400">
                                <?= e(number_format($table['row_estimate'])) ?>
                            </td>
                            <td class="num px-5 py-2.5 text-right text-ink-500">
                                <?= e(number_format($table['size_bytes'] / 1024, 0)) ?> KB
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Logs -->
    <?php if ($canSeeLogs): ?>
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Recent log entries</h3>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach ($board['log_counts'] as $count): ?>
                        <span class="badge <?= in_array($count['level'], ['error', 'critical', 'alert', 'emergency'], true)
                            ? 'badge-bear'
                            : ($count['level'] === 'warning' ? 'badge-neutral text-warn-400 border-warn-400/30' : 'badge-neutral') ?>">
                            <?= e($count['level']) ?>: <?= e((string) $count['total']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($board['logs'] === []): ?>
                <?= $this->partial('partials.empty', [
                    'message' => 'No entries in the database log',
                    'detail'  => 'Full-fidelity logs are written to rotated files under storage/logs; '
                        . 'this table carries only the UI-surfaced subset.',
                ]) ?>
            <?php else: ?>
                <ul class="divide-y divide-base-800">
                    <?php foreach ($board['logs'] as $log): ?>
                        <?php
                        $level = strtolower((string) $log['level']);
                        $tone = match (true) {
                            in_array($level, ['error', 'critical', 'alert', 'emergency'], true) => 'text-bear-400',
                            $level === 'warning'                                                => 'text-warn-400',
                            default                                                             => 'text-ink-400',
                        };
                        ?>
                        <li class="flex flex-wrap items-start gap-3 px-5 py-3">
                            <span class="num w-16 shrink-0 text-xs uppercase <?= $tone ?>"><?= e($level) ?></span>
                            <span class="flex-1 text-sm text-ink-300"><?= e((string) $log['message']) ?></span>
                            <span class="num text-xs text-ink-500"><?= e(substr((string) $log['created_at'], 0, 19)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
