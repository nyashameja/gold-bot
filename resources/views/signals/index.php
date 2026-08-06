<?php
/**
 * Signals.
 *
 * Filtered and paginated in SQL. The rows and their targets come from two
 * queries regardless of page size — the shape that fetches targets per row is
 * the classic index-page N+1 and gets slower every week the system runs.
 *
 * @var array<string,mixed> $board
 */
$filters = $board['filters'];
$options = $board['options'];

// Reflect the sanitised filters back into the form, so a value the repository
// ignored does not sit in the field looking as though it applied.
$selected = [
    'state'     => $filters['open_only'] ?? false ? 'OPEN' : ($filters['state'] ?? ''),
    'direction' => $filters['direction'] ?? '',
];
?>

<div x-data="signalList" class="space-y-5">

    <!-- Filters -->
    <form method="get" action="/signals" class="card p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="label" for="filter-state">State</label>
                <select id="filter-state" name="state" class="input">
                    <option value="">All states</option>
                    <?php foreach ($options['states'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"
                            <?= $selected['state'] === $option['value'] ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="label" for="filter-direction">Direction</label>
                <select id="filter-direction" name="direction" class="input">
                    <option value="">Both</option>
                    <?php foreach ($options['directions'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"
                            <?= $selected['direction'] === $option['value'] ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="label" for="filter-timeframe">Timeframe</label>
                <select id="filter-timeframe" name="timeframe" class="input">
                    <option value="">Any</option>
                    <?php foreach ($options['timeframes'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="label" for="filter-strategy">Strategy</label>
                <select id="filter-strategy" name="strategy" class="input">
                    <option value="">Any</option>
                    <?php foreach ($options['strategies'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-primary flex-1">Filter</button>
                <a href="/signals" class="btn btn-ghost">Reset</a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="card">
        <?php if ($board['items'] === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No signals match',
                'detail'  => 'Either nothing has been published yet, or the filters above exclude '
                    . 'everything. A strategy that is disabled publishes nothing at all.',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="w-full min-w-[900px] text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 font-medium">Generated</th>
                            <th class="px-5 py-3 font-medium">Dir</th>
                            <th class="px-5 py-3 font-medium">State</th>
                            <th class="px-5 py-3 text-right font-medium">Score</th>
                            <th class="px-5 py-3 text-right font-medium">Entry</th>
                            <th class="px-5 py-3 text-right font-medium">Stop</th>
                            <th class="px-5 py-3 text-center font-medium">Targets</th>
                            <th class="px-5 py-3 text-right font-medium">R</th>
                            <th class="px-5 py-3 font-medium">TF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($board['items'] as $signal): ?>
                            <tr class="border-b border-base-800 transition last:border-0 hover:bg-base-850/60">
                                <td class="px-5 py-3">
                                    <a href="/signals/<?= e($signal['uuid']) ?>"
                                       class="num text-xs text-gold-400 hover:text-gold-300">
                                        <?= e(str_replace('T', ' ', substr($signal['generated_at'], 0, 16))) ?>
                                    </a>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="badge badge-<?= $signal['is_buy'] ? 'bull' : 'bear' ?>">
                                        <?= $signal['is_buy'] ? 'Buy' : 'Sell' ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <?= $this->partial('partials.status-pill', [
                                        'status' => $signal['state'],
                                        'label'  => $signal['state_label'],
                                    ]) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-gold-400">
                                    <?= e(number_format($signal['score'], 1)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-200">
                                    <?= e(number_format($signal['entry'], 2)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-bear-400">
                                    <?= e(number_format($signal['stop'], 2)) ?>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <?php foreach ($signal['targets'] as $target): ?>
                                            <span class="dot <?= $target['hit'] ? 'bg-bull-500' : 'bg-base-600' ?>"
                                                  title="TP<?= e((string) $target['level']) ?> at <?= e(number_format($target['price'], 2)) ?><?= $target['hit'] ? ' — hit' : '' ?>"></span>
                                        <?php endforeach; ?>
                                        <?php if ($signal['targets'] === []): ?>
                                            <span class="text-xs text-ink-500">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="num px-5 py-3 text-right <?= $signal['realised_r'] === null
                                        ? 'text-ink-500'
                                        : ($signal['realised_r'] > 0 ? 'text-bull-400' : ($signal['realised_r'] < 0 ? 'text-bear-400' : 'text-ink-300')) ?>">
                                    <?= $signal['realised_r'] === null
                                        ? '—'
                                        : e(($signal['realised_r'] > 0 ? '+' : '') . number_format($signal['realised_r'], 2)) ?>
                                </td>
                                <td class="px-5 py-3 text-xs text-ink-500"><?= e($signal['timeframe']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?= $this->partial('partials.pagination', [
            'page'  => $board['page'],
            'query' => array_filter([
                'state'     => $selected['state'],
                'direction' => $selected['direction'],
            ], static fn (mixed $v): bool => $v !== '' && $v !== false),
            'path'  => '/signals',
        ]) ?>
    </div>
</div>
