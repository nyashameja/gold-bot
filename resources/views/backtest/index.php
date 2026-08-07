<?php
/**
 * Backtest runs.
 *
 * @var list<array<string,mixed>> $runs
 */
?>

<div class="space-y-6">

    <div class="rounded-xl border border-base-750 bg-base-900 px-4 py-3">
        <p class="text-xs text-ink-500">
            Backtests replay stored candles through the same strategy objects the live engine uses, so
            a result here is about the strategy rather than about a second implementation of it. They
            never write to the live signal record. Run one with
            <code class="num text-ink-400">php cron/run.php backtest:run &lt;strategy&gt;</code>, or sweep
            thresholds with <code class="num text-ink-400">backtest:sweep</code>.
        </p>
    </div>

    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Runs</h3>
        </div>

        <?php if ($runs === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No backtests yet',
                'detail'  => 'A sweep is minutes of CPU over hundreds of thousands of bars, so runs are '
                    . 'started from the CLI rather than from a web request that would time out halfway '
                    . 'through and leave you unsure whether it finished.',
                'icon'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="w-full min-w-[900px] text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 font-medium">Run</th>
                            <th class="px-5 py-3 font-medium">Strategy</th>
                            <th class="px-5 py-3 font-medium">Period</th>
                            <th class="px-5 py-3 text-right font-medium">Threshold</th>
                            <th class="px-5 py-3 text-right font-medium">Closed</th>
                            <th class="px-5 py-3 text-right font-medium">Win %</th>
                            <th class="px-5 py-3 text-right font-medium">Expectancy</th>
                            <th class="px-5 py-3 text-right font-medium">Net R</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <?php
                            $closed = (int) $run['trades_closed'];
                            $netR = (float) $run['total_r'];
                            ?>
                            <tr class="border-b border-base-800 transition last:border-0 hover:bg-base-850/60">
                                <td class="px-5 py-3">
                                    <a href="/backtests/<?= e((string) $run['uuid']) ?>"
                                       class="num text-xs text-gold-400 hover:text-gold-300">
                                        <?= e(substr((string) $run['uuid'], 0, 8)) ?>
                                    </a>
                                    <?php if ($run['label'] !== null && $run['label'] !== ''): ?>
                                        <div class="mt-0.5 truncate text-xs text-ink-500"><?= e((string) $run['label']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="text-ink-200"><?= e((string) $run['strategy_code']) ?></span>
                                    <span class="ml-1 text-xs text-ink-500"><?= e((string) $run['timeframe_code']) ?></span>
                                    <?php if ((int) $run['news_filter'] === 1): ?>
                                        <span class="badge badge-neutral ml-1">news</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num px-5 py-3 text-xs text-ink-400">
                                    <?= e(substr((string) $run['period_from'], 0, 10)) ?>
                                    → <?= e(substr((string) $run['period_to'], 0, 10)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-gold-400">
                                    <?= e(number_format((float) $run['min_score'], 1)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= e((string) $closed) ?>
                                    <?php if ($closed < 30): ?>
                                        <span class="text-ink-500"
                                              title="Fewer than 30 closed trades — the rates beside this are not yet a measurement.">*</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= $run['win_rate'] === null ? '—' : e(number_format((float) $run['win_rate'], 1)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= $run['expectancy_r'] === null ? '—' : e(number_format((float) $run['expectancy_r'], 3)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right <?= $netR > 0 ? 'text-bull-400' : ($netR < 0 ? 'text-bear-400' : 'text-ink-400') ?>">
                                    <?= e(($netR > 0 ? '+' : '') . number_format($netR, 2)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="border-t border-base-750 px-5 py-3 text-xs text-ink-500">
                * fewer than 30 closed trades. Do not tune a threshold on a row marked this way — the
                rates are noise, and a number chosen from them acquires authority it has not earned.
            </p>
        <?php endif; ?>
    </div>
</div>
