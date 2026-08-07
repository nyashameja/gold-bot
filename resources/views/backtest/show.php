<?php
/**
 * One backtest run.
 *
 * @var array<string,mixed> $run
 * @var list<array<string,mixed>> $trades
 * @var list<array<string,mixed>> $bands
 */
$closed = (int) $run['trades_closed'];
$netR = (float) $run['total_r'];
$significant = $closed >= 30;

// The equity curve is rebuilt here from the stored trades rather than saved
// alongside them: it is derivable, and a stored copy is one more thing that can
// disagree with the trades it claims to summarise.
$equity = [];
$running = 0.0;

foreach ($trades as $trade) {
    if ($trade['realised_r'] === null) {
        continue;
    }

    $running = round($running + (float) $trade['realised_r'], 3);
    $equity[] = ['t' => substr((string) $trade['closed_at'], 0, 16), 'equity' => $running];
}
?>

<div x-data="backtestCharts"
     data-equity="<?= e(json_encode($equity, JSON_THROW_ON_ERROR)) ?>"
     data-bands="<?= e(json_encode($bands, JSON_THROW_ON_ERROR)) ?>"
     x-init="start"
     class="space-y-6">

    <a href="/backtests" class="inline-flex items-center gap-1.5 text-xs text-ink-400 hover:text-ink-100">
        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m15 18-6-6 6-6"/>
        </svg>
        All backtests
    </a>

    <!-- Header -->
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-ink-100"><?= e((string) $run['strategy_name']) ?></h2>
                    <span class="badge badge-gold"><?= e((string) $run['strategy_code']) ?></span>
                    <span class="badge badge-neutral"><?= e((string) $run['timeframe_code']) ?></span>
                    <?php if ((int) $run['news_filter'] === 1): ?>
                        <span class="badge badge-neutral">news filter on</span>
                    <?php endif; ?>
                </div>
                <p class="num mt-2 text-xs text-ink-500"><?= e((string) $run['uuid']) ?></p>
                <?php if ($run['label'] !== null && $run['label'] !== ''): ?>
                    <p class="mt-1 text-sm text-ink-300"><?= e((string) $run['label']) ?></p>
                <?php endif; ?>
            </div>

            <div class="text-right">
                <div class="stat-label">Threshold</div>
                <div class="stat-value text-gold-400"><?= e(number_format((float) $run['min_score'], 1)) ?></div>
            </div>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-base-800 pt-4 text-xs sm:grid-cols-4">
            <?php
            $facts = [
                'Period'         => substr((string) $run['period_from'], 0, 10) . ' → ' . substr((string) $run['period_to'], 0, 10),
                'Bars evaluated' => number_format((int) $run['bars_evaluated']),
                'Signals'        => (string) (int) $run['signals_generated'],
                'Still open'     => (string) (int) $run['still_open'],
            ];
            ?>
            <?php foreach ($facts as $label => $value): ?>
                <div>
                    <dt class="text-ink-500"><?= e($label) ?></dt>
                    <dd class="num mt-0.5 text-ink-200"><?= e($value) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>

    <?php if (!$significant): ?>
        <div class="rounded-xl border border-warn-400/30 bg-warn-400/10 px-4 py-3">
            <p class="text-sm text-warn-400">
                <strong><?= e((string) $closed) ?> closed trade<?= $closed === 1 ? '' : 's' ?>.</strong>
                The figures below are not yet a measurement. Do not tune a threshold on them — a number
                chosen from a sample this thin acquires authority it has not earned, and every later
                decision compounds the error (ADR-04).
            </p>
        </div>
    <?php endif; ?>

    <!-- Metrics -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= $this->partial('partials.stat', [
            'label' => 'Net R',
            'value' => ($netR >= 0 ? '+' : '') . number_format($netR, 2),
            'sub'   => sprintf('%d closed · %dW / %dL / %dBE', $closed, (int) $run['wins'], (int) $run['losses'], (int) $run['breakeven']),
            'tone'  => $netR > 0 ? 'bull' : ($netR < 0 ? 'bear' : null),
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Win rate',
            'value' => $run['win_rate'] === null ? '—' : number_format((float) $run['win_rate'], 1) . '%',
            'sub'   => $significant ? 'Over a meaningful sample' : 'Sample too thin to read',
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Expectancy',
            'value' => $run['expectancy_r'] === null ? '—' : number_format((float) $run['expectancy_r'], 3) . 'R',
            'sub'   => 'R per signal — what one more trade is worth',
            'tone'  => $run['expectancy_r'] === null ? null : ((float) $run['expectancy_r'] > 0 ? 'bull' : 'bear'),
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Profit factor',
            'value' => $run['profit_factor'] === null ? '—' : number_format((float) $run['profit_factor'], 2),
            'sub'   => $run['profit_factor'] === null ? 'Undefined — no losing trades' : 'Gross won ÷ gross lost',
        ]) ?>
    </div>

    <?php if ($equity !== []): ?>
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Equity curve</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Cumulative R, one point per closed trade. Max drawdown
                    <span class="num text-bear-400"><?= e(number_format((float) $run['max_drawdown_r'], 2)) ?>R</span> —
                    what this would have put you through, as opposed to where it finished.
                </p>
            </div>
            <div class="p-4">
                <div class="relative h-[300px] w-full">
                    <canvas x-ref="equityChart" role="img" aria-label="Cumulative R across the backtest"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($bands !== []): ?>
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Outcome by score band</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    The distribution that sets a threshold empirically rather than by intuition. If a
                    higher score does not win more often, the score is not measuring anything and no
                    threshold will fix that.
                </p>
            </div>
            <div class="p-4">
                <div class="relative h-[280px] w-full">
                    <canvas x-ref="bandChart" role="img" aria-label="Win rate by score band"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Trades -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Trades</h3>
        </div>

        <?php if ($trades === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'This run produced no trades',
                'detail'  => 'Either the threshold was above everything the strategy scored, or the '
                    . 'period contains no qualifying setup.',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="w-full min-w-[880px] text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 font-medium">Signalled</th>
                            <th class="px-5 py-3 font-medium">Dir</th>
                            <th class="px-5 py-3 text-right font-medium">Score</th>
                            <th class="px-5 py-3 text-right font-medium">Entry</th>
                            <th class="px-5 py-3 text-right font-medium">Stop</th>
                            <th class="px-5 py-3 font-medium">Outcome</th>
                            <th class="px-5 py-3 text-right font-medium">TPs</th>
                            <th class="px-5 py-3 text-right font-medium">R</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trades as $trade): ?>
                            <?php
                            $outcome = \GoldBot\Domain\Backtest\TradeOutcomeType::from((string) $trade['outcome']);
                            $r = $trade['realised_r'] === null ? null : (float) $trade['realised_r'];
                            $isBuy = $trade['direction'] === 'BUY';
                            ?>
                            <tr class="border-b border-base-800 last:border-0">
                                <td class="num px-5 py-3 text-xs text-ink-400">
                                    <?= e(substr((string) $trade['signalled_at'], 0, 16)) ?>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="badge badge-<?= $isBuy ? 'bull' : 'bear' ?>">
                                        <?= $isBuy ? 'Buy' : 'Sell' ?>
                                    </span>
                                </td>
                                <td class="num px-5 py-3 text-right text-gold-400">
                                    <?= e(number_format((float) $trade['score'], 1)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-200">
                                    <?= e(number_format((float) $trade['entry_price'], 2)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-bear-400">
                                    <?= e(number_format((float) $trade['stop_loss'], 2)) ?>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="badge badge-<?= $outcome->tone() === 'neutral' ? 'neutral' : $outcome->tone() ?>">
                                        <?= e($outcome->label()) ?>
                                    </span>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= e((string) (int) $trade['targets_hit']) ?>
                                </td>
                                <td class="num px-5 py-3 text-right <?= $r === null ? 'text-ink-500' : ($r > 0 ? 'text-bull-400' : ($r < 0 ? 'text-bear-400' : 'text-ink-300')) ?>">
                                    <?= $r === null ? '—' : e(($r > 0 ? '+' : '') . number_format($r, 2)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
