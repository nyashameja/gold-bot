<?php
/**
 * Performance.
 *
 * Everything is denominated in R, the risk multiple. Two signals with
 * different stop distances are not comparable in pips or in percent — a
 * dashboard that reports either one flatters wide stops and punishes tight
 * ones.
 *
 * Untraded outcomes (cancelled, expired) are counted separately and excluded
 * from every rate on this page. Including them would drag the win rate toward
 * zero and describe a strategy nobody ran.
 *
 * @var array<string,mixed> $report
 */
$s = $report['summary'];
$hasData = $s['total'] > 0;
?>

<div x-data="performanceCharts"
     data-equity="<?= e(json_encode($report['equity'], JSON_THROW_ON_ERROR)) ?>"
     data-bands="<?= e(json_encode($report['bands'], JSON_THROW_ON_ERROR)) ?>"
     x-init="start"
     class="space-y-6">

    <!-- Window -->
    <form method="get" action="/performance" class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-[8rem] flex-1">
            <label class="label" for="perf-days">Window</label>
            <select id="perf-days" name="days" class="input">
                <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year', 3650 => 'All time'] as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"
                        <?= $report['window']['days'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem] flex-1">
            <label class="label" for="perf-strategy">Strategy</label>
            <select id="perf-strategy" name="strategy" class="input">
                <option value="">All strategies</option>
                <?php foreach ($report['strategies'] as $option): ?>
                    <option value="<?= e($option['value']) ?>"
                        <?= $report['strategy'] === $option['value'] ? 'selected' : '' ?>>
                        <?= e($option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Apply</button>
    </form>

    <?php if (!$hasData): ?>
        <div class="card">
            <?= $this->partial('partials.empty', [
                'message' => 'No closed signals in this window',
                'detail'  => 'Performance is computed from signals that actually traded. Cancelled and '
                    . 'expired signals never held a position and are excluded from every rate below.',
                'icon'    => '<path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/>',
            ]) ?>
        </div>
    <?php else: ?>

        <!-- Headline -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= $this->partial('partials.stat', [
                'label' => 'Net R',
                'value' => ($s['net_r'] >= 0 ? '+' : '') . number_format($s['net_r'], 2),
                'sub'   => sprintf('%d closed signals', $s['total']),
                'tone'  => $s['net_r'] > 0 ? 'bull' : ($s['net_r'] < 0 ? 'bear' : null),
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Win rate',
                'value' => number_format((float) $s['win_rate'], 1) . '%',
                'sub'   => sprintf('%dW / %dL / %dBE', $s['wins'], $s['losses'], $s['breakeven']),
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Expectancy',
                'value' => number_format((float) $s['expectancy_r'], 3) . 'R',
                'sub'   => 'Average R per signal',
                'tone'  => $s['expectancy_r'] > 0 ? 'bull' : 'bear',
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Profit factor',
                // Undefined rather than infinite when there are no losses:
                // a placeholder large number makes an untested strategy look
                // like the best one on the page.
                'value' => $s['profit_factor'] === null ? '—' : number_format($s['profit_factor'], 2),
                'sub'   => $s['profit_factor'] === null
                    ? 'Undefined — no losing signals yet'
                    : sprintf('%s won / %s lost', number_format($s['gross_profit_r'], 1), number_format($s['gross_loss_r'], 1)),
                'tone'  => $s['profit_factor'] === null ? null : ($s['profit_factor'] >= 1 ? 'bull' : 'bear'),
            ]) ?>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= $this->partial('partials.stat', [
                'label' => 'Max drawdown',
                'value' => '-' . number_format($s['max_drawdown_r'], 2) . 'R',
                'sub'   => 'Peak to trough of the equity curve',
                'tone'  => $s['max_drawdown_r'] > 0 ? 'bear' : null,
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Best / worst',
                'value' => number_format((float) $s['best_r'], 2) . ' / ' . number_format((float) $s['worst_r'], 2),
                'sub'   => 'Single-signal extremes, in R',
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Longest streaks',
                'value' => $s['longest_win_streak'] . 'W / ' . $s['longest_loss_streak'] . 'L',
                'sub'   => $s['current_streak'] === 0
                    ? 'No run in progress'
                    : sprintf('Currently %d %s', abs($s['current_streak']), $s['current_streak'] > 0 ? 'wins' : 'losses'),
                'tone'  => $s['current_streak'] > 0 ? 'bull' : ($s['current_streak'] < 0 ? 'bear' : null),
            ]) ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Average score',
                'value' => $s['avg_score'] === null ? '—' : number_format($s['avg_score'], 1),
                'sub'   => 'Across closed signals',
            ]) ?>
        </div>

        <!-- Equity curve -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Equity curve</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Cumulative R, one point per closed signal. Not time-weighted — a flat stretch means
                    nothing closed, not that nothing happened.
                </p>
            </div>
            <div class="p-4">
                <div class="relative h-[300px] w-full">
                    <canvas x-ref="equityChart" role="img"
                            aria-label="Cumulative R over <?= e((string) $s['total']) ?> closed signals"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

            <!-- Score bands -->
            <div class="card">
                <div class="border-b border-base-750 px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink-100">Win rate by score band</h3>
                    <p class="mt-0.5 text-xs text-ink-500">
                        The only chart that justifies a threshold: if higher scores do not win more often,
                        the score is not measuring anything.
                    </p>
                </div>
                <div class="p-4">
                    <div class="relative h-[280px] w-full">
                        <canvas x-ref="bandChart" role="img" aria-label="Win rate by score band"></canvas>
                    </div>
                </div>
            </div>

            <!-- Target hit rates -->
            <div class="card">
                <div class="border-b border-base-750 px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink-100">Target hit rates</h3>
                    <p class="mt-0.5 text-xs text-ink-500">
                        Out of signals that activated. Eligibility is counted per level, so a two-target
                        signal does not count against TP3.
                    </p>
                </div>
                <?php if ($report['targets'] === []): ?>
                    <?= $this->partial('partials.empty', ['message' => 'No activated signals in this window']) ?>
                <?php else: ?>
                    <div class="space-y-4 p-5">
                        <?php foreach ($report['targets'] as $target): ?>
                            <div>
                                <div class="mb-1.5 flex items-center justify-between text-sm">
                                    <span class="text-ink-300">TP<?= e((string) $target['level']) ?></span>
                                    <span class="num text-ink-400">
                                        <?= e(number_format((float) $target['rate'], 1)) ?>%
                                        <span class="text-ink-500">
                                            (<?= e((string) $target['hit']) ?>/<?= e((string) $target['eligible']) ?>)
                                        </span>
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-base-850">
                                    <div class="h-full rounded-full bg-gold-500"
                                         style="width: <?= e((string) (float) $target['rate']) ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Breakdowns -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <?php
            $breakdowns = [
                'direction' => 'By direction',
                'session'   => 'By session',
                'timeframe' => 'By timeframe',
                'weekday'   => 'By weekday',
                'hour'      => 'By hour (UTC)',
                'month'     => 'By month',
            ];
            ?>
            <?php foreach ($breakdowns as $key => $heading): ?>
                <?php $rows = $report['by'][$key]; ?>
                <div class="card">
                    <div class="border-b border-base-750 px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink-100"><?= e($heading) ?></h3>
                    </div>
                    <?php if ($rows === []): ?>
                        <?= $this->partial('partials.empty', ['message' => 'Nothing to break down yet']) ?>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                        <th class="px-5 py-2.5 font-medium">Bucket</th>
                                        <th class="px-5 py-2.5 text-right font-medium">N</th>
                                        <th class="px-5 py-2.5 text-right font-medium">Win %</th>
                                        <th class="px-5 py-2.5 text-right font-medium">Net R</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr class="border-b border-base-800 last:border-0">
                                            <td class="px-5 py-2.5 text-ink-200">
                                                <?= e(ucwords(strtolower(str_replace('_', ' ', $row['bucket'])))) ?>
                                            </td>
                                            <td class="num px-5 py-2.5 text-right text-ink-400"><?= e((string) $row['total']) ?></td>
                                            <td class="num px-5 py-2.5 text-right text-ink-400">
                                                <?= $row['win_rate'] === null ? '—' : e(number_format($row['win_rate'], 0)) ?>
                                            </td>
                                            <td class="num px-5 py-2.5 text-right <?= $row['net_r'] > 0 ? 'text-bull-400' : ($row['net_r'] < 0 ? 'text-bear-400' : 'text-ink-400') ?>">
                                                <?= e(($row['net_r'] > 0 ? '+' : '') . number_format($row['net_r'], 2)) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Period trend, from the stored rollups -->
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-750 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-ink-100">Period by period</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Read from the nightly rollups. Drawdown and streaks do not add up across period
                    boundaries, so each row is measured over its own window rather than sliced out of
                    the totals above.
                </p>
            </div>
            <?php if ($report['trend']['built_at'] !== null): ?>
                <span class="num text-xs text-ink-500">
                    built <?= e(substr((string) $report['trend']['built_at'], 0, 16)) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!$report['trend']['available']): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No snapshots have been built',
                'detail'  => 'Run php cron/run.php performance:rebuild, or wait for the nightly task. '
                    . 'This panel deliberately does not fall back to computing the periods live — that '
                    . 'would hide a scheduler that has stopped.',
                'icon'    => '<path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/>',
            ]) ?>
        <?php else: ?>
            <div x-data="periodTrend" class="p-4">
                <div class="mb-4 flex flex-wrap gap-2">
                    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?>
                        <button type="button"
                                class="btn"
                                :class="tabClass"
                                data-period="<?= e($key) ?>"
                                @click="select">
                            <?= e($label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($report['trend']['periods'] as $key => $rows): ?>
                    <div x-show="isActive" data-period="<?= e($key) ?>" x-cloak>
                        <?php if ($rows === []): ?>
                            <p class="px-2 py-6 text-center text-sm text-ink-500">
                                No <?= e($key) ?> period has closed a signal yet.
                            </p>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table class="w-full min-w-[720px] text-sm">
                                    <thead>
                                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                            <th class="px-4 py-2.5 font-medium">Period</th>
                                            <th class="px-4 py-2.5 text-right font-medium">N</th>
                                            <th class="px-4 py-2.5 text-right font-medium">W / L / BE</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Win %</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Net R</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Max DD</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Streaks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($rows) as $row): ?>
                                            <?php $m = $row['metrics']; ?>
                                            <tr class="border-b border-base-800 last:border-0">
                                                <td class="px-4 py-2.5 text-ink-200"><?= e($row['label']) ?></td>
                                                <td class="num px-4 py-2.5 text-right text-ink-400">
                                                    <?= e((string) $m['total']) ?>
                                                    <?php if (!$m['significant']): ?>
                                                        <span class="text-ink-500"
                                                              title="Too few signals for the rates beside this to mean much.">*</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="num px-4 py-2.5 text-right text-ink-400">
                                                    <?= e($m['wins'] . ' / ' . $m['losses'] . ' / ' . $m['breakeven']) ?>
                                                </td>
                                                <td class="num px-4 py-2.5 text-right text-ink-400">
                                                    <?= $m['win_rate'] === null ? '—' : e(number_format($m['win_rate'], 1)) ?>
                                                </td>
                                                <td class="num px-4 py-2.5 text-right <?= $m['net_r'] > 0 ? 'text-bull-400' : ($m['net_r'] < 0 ? 'text-bear-400' : 'text-ink-400') ?>">
                                                    <?= e(($m['net_r'] > 0 ? '+' : '') . number_format($m['net_r'], 2)) ?>
                                                </td>
                                                <td class="num px-4 py-2.5 text-right text-ink-500">
                                                    <?= e(number_format($m['max_drawdown_r'], 2)) ?>
                                                </td>
                                                <td class="num px-4 py-2.5 text-right text-ink-500">
                                                    <?= e($m['longest_win_streak'] . 'W / ' . $m['longest_loss_streak'] . 'L') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="px-4 pt-3 text-xs text-ink-500">
                                * fewer than 30 signals — the rates beside it are not yet a measurement.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- State counts, including the excluded ones -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">All signals generated in this window</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                Shown so the exclusions above are visible rather than silent. Cancelled and expired
                signals never traded and are not counted in any rate.
            </p>
        </div>
        <div class="grid grid-cols-2 gap-px bg-base-800 sm:grid-cols-4 lg:grid-cols-8">
            <?php foreach ($report['states'] as $state => $count): ?>
                <div class="bg-base-900 px-4 py-4 text-center">
                    <div class="num text-lg font-semibold text-ink-100"><?= e((string) $count) ?></div>
                    <div class="mt-0.5 text-xs text-ink-500">
                        <?= e(ucwords(strtolower(str_replace('_', ' ', $state)))) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="flex justify-end">
        <?= $this->partial('partials.data-age', ['age' => $report['age'], 'prefix' => 'Last close']) ?>
    </div>
</div>
