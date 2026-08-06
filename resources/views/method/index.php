<?php
/**
 * The 714 Method.
 *
 * This renders the ACTIVE CONFIG VERSION, not a description of the strategy.
 * The rules are data (ADR-06) — a prose page would go stale the first time
 * anyone retuned them. What is on this screen is what the engine will apply on
 * its next run.
 *
 * @var array<string,mixed> $board
 * @var list<array<string,mixed>> $strategies
 */
$config = $board['config'];
$strategy = $board['strategy'];
?>

<div class="space-y-6">

    <!-- Strategy switcher -->
    <?php if (count($strategies) > 1): ?>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($strategies as $option): ?>
                <?php $active = $option['code'] === $strategy['code']; ?>
                <a href="/method?strategy=<?= e($option['code']) ?>"
                   class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?>">
                    <?= e($option['name']) ?>
                    <?php if (!$option['enabled']): ?>
                        <span class="text-xs opacity-70">(off)</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-ink-100"><?= e($strategy['name']) ?></h2>
                    <?= $this->partial('partials.status-pill', [
                        'status' => $strategy['enabled'] ? 'OK' : 'UNKNOWN',
                        'label'  => $strategy['enabled'] ? 'Enabled' : 'Disabled',
                    ]) ?>
                </div>
                <p class="mt-2 text-sm text-ink-400"><?= e($strategy['description']) ?></p>
            </div>

            <?php if ($config !== null): ?>
                <div class="text-right">
                    <div class="stat-label">Config version</div>
                    <div class="stat-value text-gold-400">v<?= e((string) $config['version']) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$strategy['enabled']): ?>
            <div class="mt-4 rounded-xl border border-warn-400/30 bg-warn-400/10 px-4 py-3">
                <p class="text-sm text-warn-400">
                    This strategy is disabled and publishes nothing. It is scored and logged on every run,
                    so the distribution below is still live — but no signal will reach Telegram.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($config === null): ?>
        <div class="card">
            <?= $this->partial('partials.empty', [
                'message' => 'No active configuration',
                'detail'  => 'Activate one with php cron/run.php strategy:config <code> <file.json>.',
                'icon'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
            ]) ?>
        </div>
    <?php else: ?>

        <!-- Config summary -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= $this->partial('partials.stat', [
                'label' => 'Signal timeframe',
                'value' => $config['signal_timeframe'],
                'sub'   => 'Evaluated once per closed bar',
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Score threshold',
                'value' => number_format($config['min_score'], 0),
                'sub'   => 'Out of 100, weighted across pillars',
                'tone'  => 'gold',
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Minimum R:R',
                'value' => number_format($config['min_risk_reward'], 2) . ':1',
                'sub'   => 'Rejected below this, whatever the score',
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Total weight',
                'value' => number_format($config['total_weight'], 0),
                'sub'   => $config['weights_balanced']
                    ? 'Weights sum to 100'
                    : 'Weights do not sum to 100 — scores are normalised, but check this was intended',
                'tone'  => $config['weights_balanced'] ? null : 'warn',
            ]) ?>
        </div>

        <!-- Pillars -->
        <div class="space-y-4">
            <?php foreach ($config['pillars'] as $pillar): ?>
                <div class="card">
                    <div class="flex flex-wrap items-center gap-3 border-b border-base-750 px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink-100">
                            <?= e(ucwords(strtolower(str_replace('_', ' ', $pillar['name'])))) ?>
                        </h3>
                        <span class="badge badge-gold"><?= e(number_format($pillar['weight'], 0)) ?>% weight</span>
                        <?php if ($pillar['gate']): ?>
                            <span class="badge badge-bear"
                                  title="A hard requirement: failing it rejects the signal regardless of the total score.">
                                Gate ≥ <?= e(number_format((float) $pillar['min_raw'], 0)) ?>
                            </span>
                        <?php endif; ?>
                        <span class="ml-auto num text-xs text-ink-500">
                            <?= e(number_format($pillar['points_available'], 0)) ?> points available
                        </span>
                    </div>

                    <div class="table-scroll">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead>
                                <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                    <th class="px-5 py-3 font-medium">Rule</th>
                                    <th class="px-5 py-3 font-medium">Type</th>
                                    <th class="px-5 py-3 font-medium">Parameters</th>
                                    <th class="px-5 py-3 text-right font-medium">Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pillar['rules'] as $rule): ?>
                                    <tr class="border-b border-base-800 last:border-0">
                                        <td class="px-5 py-3 text-ink-100"><?= e($rule['id']) ?></td>
                                        <td class="px-5 py-3 text-ink-400"><?= e($rule['type']) ?></td>
                                        <td class="px-5 py-3">
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php foreach ($rule['parameters'] as $key => $value): ?>
                                                    <span class="badge badge-neutral">
                                                        <?= e((string) $key) ?>:
                                                        <?= e(is_array($value) ? implode('/', array_map('strval', $value)) : (string) $value) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="num px-5 py-3 text-right text-gold-400">
                                            <?= e(number_format($rule['points'], 0)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Entry / stop / targets -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <?php
            $blocks = [
                'Direction' => $config['direction'],
                'Stop loss' => $config['stop'],
            ];
            ?>
            <?php foreach ($blocks as $heading => $values): ?>
                <div class="card p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink-100"><?= e($heading) ?></h3>
                    <dl class="space-y-2 text-sm">
                        <?php foreach ($values as $key => $value): ?>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500"><?= e((string) $key) ?></dt>
                                <dd class="num text-ink-200">
                                    <?= e(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value) ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>

            <div class="card p-5">
                <h3 class="mb-3 text-sm font-semibold text-ink-100">Targets</h3>
                <ol class="space-y-2 text-sm">
                    <?php foreach ($config['targets'] as $i => $target): ?>
                        <li class="flex items-center justify-between gap-3">
                            <span class="text-ink-500">TP<?= e((string) ($i + 1)) ?></span>
                            <span class="num text-ink-200">
                                <?= e(number_format((float) ($target['r'] ?? 0), 1)) ?>R
                                <span class="text-ink-500">
                                    · close <?= e((string) ($target['close_percent'] ?? 0)) ?>%
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    <?php endif; ?>

    <!-- Score distribution -->
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-750 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-ink-100">Score distribution</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Every evaluation in the last <?= e((string) $board['window']['days']) ?> days, published or not.
                    A threshold with nothing either side of it is not selective — it is unreachable.
                </p>
            </div>
            <?= $this->partial('partials.data-age', ['age' => $board['age'], 'prefix' => 'Last run']) ?>
        </div>

        <?php if ($board['distribution'] === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No evaluations recorded',
                'detail'  => 'Run php cron/run.php task signals.analyse, or wait for the scheduler.',
            ]) ?>
        <?php else: ?>
            <?php $peak = max($board['distribution']) ?: 1; ?>
            <div class="space-y-2 p-5">
                <?php foreach ($board['distribution'] as $band => $count): ?>
                    <?php
                    $low = (int) explode('-', (string) $band)[0];
                    $above = $config !== null && $low >= $config['min_score'];
                    ?>
                    <div class="flex items-center gap-3">
                        <span class="num w-16 shrink-0 text-xs text-ink-500"><?= e((string) $band) ?></span>
                        <div class="h-4 flex-1 overflow-hidden rounded bg-base-850">
                            <div class="h-full rounded <?= $above ? 'bg-gold-500' : 'bg-base-600' ?>"
                                 style="width: <?= e((string) round(($count / $peak) * 100, 1)) ?>%"></div>
                        </div>
                        <span class="num w-12 shrink-0 text-right text-xs text-ink-400"><?= e((string) $count) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($config !== null): ?>
                    <p class="pt-2 text-xs text-ink-500">
                        Gold bars are at or above the current threshold of
                        <?= e(number_format($config['min_score'], 0)) ?>.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Version history -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Configuration history</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                Versions are immutable (ADR-06), so every past signal stays attributable to the exact
                rules that produced it.
            </p>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[560px] text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 font-medium">Version</th>
                        <th class="px-5 py-3 font-medium">Created</th>
                        <th class="px-5 py-3 font-medium">By</th>
                        <th class="px-5 py-3 text-right font-medium">Signals</th>
                        <th class="px-5 py-3 font-medium">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($board['history'] as $version): ?>
                        <tr class="border-b border-base-800 last:border-0">
                            <td class="px-5 py-3">
                                <span class="num text-ink-100">v<?= e((string) $version['version']) ?></span>
                                <?php if ($version['is_active']): ?>
                                    <span class="badge badge-gold ml-2">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="num px-5 py-3 text-xs text-ink-400"><?= e(substr($version['created_at'], 0, 16)) ?></td>
                            <td class="px-5 py-3 text-xs text-ink-400"><?= e($version['created_by']) ?></td>
                            <td class="num px-5 py-3 text-right text-ink-400"><?= e((string) $version['signal_count']) ?></td>
                            <td class="px-5 py-3 text-xs text-ink-500"><?= e($version['notes'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
