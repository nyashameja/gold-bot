<?php
/**
 * Live Market.
 *
 * Two charts: the TradingView Advanced Chart for discretionary reading, and a
 * Chart.js price panel drawn from OUR OWN stored candles. The second one is
 * not redundant — TradingView draws its own data feed, so if our ingest is
 * behind, its chart still looks perfect while every signal on the page was
 * computed from something older. The local panel is the one that shows what
 * the engine actually saw.
 *
 * TradingView is a third-party script and the only external request this
 * application makes from a browser. It is loaded lazily and degrades to a
 * message when it cannot be reached, so the page stays fully usable offline.
 *
 * @var array<string,mixed> $instrument
 * @var \GoldBot\Domain\Market\Timeframe $timeframe
 * @var array<string,mixed> $quote
 * @var array<string,mixed> $chart
 * @var array<string,mixed> $overlays
 * @var list<array<string,mixed>> $timeframes
 * @var list<array<string,mixed>> $sessions
 */
$changeTone = match ($quote['direction'] ?? 'FLAT') {
    'UP'    => 'text-bull-400',
    'DOWN'  => 'text-bear-400',
    default => 'text-ink-300',
};

$activeSessions = array_values(array_filter($sessions, static fn (array $s): bool => $s['active']));
?>

<div x-data="market"
     x-init="start"
     data-symbol="<?= e((string) $instrument['symbol']) ?>"
     data-timeframe="<?= e($timeframe->code) ?>"
     data-chart="<?= e(json_encode($chart, JSON_THROW_ON_ERROR)) ?>"
     data-overlays="<?= e(json_encode($overlays, JSON_THROW_ON_ERROR)) ?>"
     class="space-y-6">

    <!-- Quote header -->
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-ink-100"><?= e((string) $instrument['symbol']) ?></h2>
                    <span class="badge badge-neutral"><?= e((string) $instrument['name']) ?></span>
                </div>

                <?php if ($quote['available']): ?>
                    <div class="mt-2 flex flex-wrap items-baseline gap-3">
                        <span class="price text-3xl font-semibold text-ink-100" x-text="price">
                            <?= e(number_format((float) $quote['price'], 2)) ?>
                        </span>
                        <?php if ($quote['change_absolute'] !== null): ?>
                            <span class="num text-sm <?= $changeTone ?>">
                                <?= (float) $quote['change_absolute'] >= 0 ? '+' : '' ?><?= e(number_format((float) $quote['change_absolute'], 2)) ?>
                                (<?= e(number_format((float) ($quote['change_percent'] ?? 0), 2)) ?>%)
                            </span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="mt-2 text-sm text-ink-500">No quote has been captured yet.</p>
                <?php endif; ?>

                <div class="mt-2">
                    <span class="inline-flex items-center gap-1.5 text-xs" :class="ageTone">
                        <span class="dot" :class="ageDot" aria-hidden="true"></span>
                        <span x-text="ageLabel">Quote <?= e($quote['age']['label']) ?></span>
                    </span>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                <?php
                $facts = [
                    'Bid'      => $quote['bid'] ?? null,
                    'Ask'      => $quote['ask'] ?? null,
                    'Day high' => $quote['day_high'] ?? null,
                    'Day low'  => $quote['day_low'] ?? null,
                ];
                ?>
                <?php foreach ($facts as $label => $value): ?>
                    <div>
                        <dt class="text-ink-500"><?= e($label) ?></dt>
                        <dd class="num mt-0.5 text-ink-200">
                            <?= $value === null ? '—' : e(number_format((float) $value, 2)) ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
                <div>
                    <dt class="text-ink-500">Spread</dt>
                    <dd class="num mt-0.5 text-ink-200">
                        <?= $quote['spread'] === null ? '—' : e(number_format((float) $quote['spread'], 2)) ?>
                    </dd>
                </div>
                <div class="col-span-2 sm:col-span-3">
                    <dt class="text-ink-500">Sessions open</dt>
                    <dd class="mt-0.5 text-ink-200">
                        <?= $activeSessions === []
                            ? 'None — market quiet'
                            : e(implode(', ', array_column($activeSessions, 'name'))) ?>
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Timeframe selector -->
    <div class="flex flex-wrap gap-2">
        <?php foreach ($timeframes as $row): ?>
            <?php $active = $row['code'] === $timeframe->code; ?>
            <a href="/market?tf=<?= e($row['code']) ?>"
               class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?>"
               <?= $active ? 'aria-current="true"' : '' ?>>
                <?= e($row['code']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- TradingView -->
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">TradingView</h3>
            <span class="text-xs text-ink-500">Third-party feed · independent of our ingest</span>
        </div>
        <div class="relative h-[420px] w-full sm:h-[520px]">
            <div id="tradingview-chart" class="h-full w-full"></div>
            <div x-show="tradingViewFailed"
                 x-cloak
                 class="absolute inset-0 flex items-center justify-center bg-base-900 px-6 text-center">
                <p class="max-w-sm text-sm text-ink-500">
                    TradingView could not be reached. Everything else on this page is served from the
                    local database and remains accurate.
                </p>
            </div>
        </div>
    </div>

    <!-- Local price panel -->
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-750 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-ink-100">Stored candles · <?= e($timeframe->code) ?></h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    What the signal engine actually evaluated, with its EMAs and overlays.
                </p>
            </div>
            <?= $this->partial('partials.data-age', ['age' => $chart['age'], 'prefix' => 'Newest bar']) ?>
        </div>

        <?php if ($chart['candles'] === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No candles stored for this timeframe',
                'detail'  => 'Run the market import task, or backfill history with '
                    . 'php cron/run.php market:backfill.',
                'icon'    => '<path d="M3 3v18h18"/><path d="m7 14 3-4 3 3 5-7"/>',
            ]) ?>
        <?php else: ?>
            <div class="p-4">
                <div class="relative h-[320px] w-full">
                    <canvas x-ref="priceChart" role="img"
                            aria-label="Closing price with EMA 50 and EMA 200 for <?= e($timeframe->code) ?>"></canvas>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Levels -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Levels and zones</h3>
            </div>
            <?php if ($overlays['levels'] === []): ?>
                <?= $this->partial('partials.empty', ['message' => 'No levels detected on this timeframe']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead>
                            <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 text-right font-medium">Price</th>
                                <th class="px-5 py-3 text-right font-medium">Strength</th>
                                <th class="px-5 py-3 text-right font-medium">Touches</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overlays['levels'] as $level): ?>
                                <?php
                                $isZone = $level['from'] !== $level['to'];
                                $tone = str_contains($level['type'], 'DEMAND') || str_contains($level['type'], 'SUPPORT')
                                        || str_contains($level['type'], 'LOW')
                                    ? 'text-bull-400'
                                    : 'text-bear-400';
                                ?>
                                <tr class="border-b border-base-800 last:border-0">
                                    <td class="px-5 py-3 <?= $tone ?>">
                                        <?= e(ucwords(strtolower(str_replace('_', ' ', $level['type'])))) ?>
                                    </td>
                                    <td class="num px-5 py-3 text-right text-ink-200">
                                        <?= $isZone
                                            ? e(number_format($level['from'], 2) . '–' . number_format($level['to'], 2))
                                            : e(number_format($level['from'], 2)) ?>
                                    </td>
                                    <td class="num px-5 py-3 text-right text-ink-400"><?= e((string) $level['strength']) ?></td>
                                    <td class="num px-5 py-3 text-right text-ink-400"><?= e((string) $level['touches']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Structure -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Structure events</h3>
            </div>
            <?php
            $breaks = array_values(array_filter(
                $overlays['points'],
                static fn (array $p): bool => in_array($p['type'], ['BOS', 'CHOCH'], true)
            ));
            $breaks = array_slice(array_reverse($breaks), 0, 12);
            ?>
            <?php if ($breaks === []): ?>
                <?= $this->partial('partials.empty', [
                    'message' => 'No breaks of structure detected',
                    'detail'  => 'A break needs confirmed swing points either side of it, so a freshly '
                        . 'backfilled series will show none until enough bars have closed.',
                ]) ?>
            <?php else: ?>
                <ul class="divide-y divide-base-800">
                    <?php foreach ($breaks as $point): ?>
                        <li class="flex items-center gap-3 px-5 py-3">
                            <span class="badge <?= $point['direction'] === 'UPTREND' ? 'badge-bull' : 'badge-bear' ?>">
                                <?= e($point['type']) ?>
                            </span>
                            <span class="num flex-1 text-sm text-ink-200"><?= e(number_format($point['price'], 2)) ?></span>
                            <span class="num text-xs text-ink-500"><?= e($point['at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
