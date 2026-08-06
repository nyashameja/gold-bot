<?php
/**
 * Overview — the one screen that answers "is it working, and how is it doing?"
 *
 * Every tile reads MySQL only (docs/01 §8) and shows the age of what it is
 * displaying. Nothing here contacts a provider, so the page renders in full
 * with the network unplugged; only the ages grow.
 *
 * @var array<string,mixed> $board
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
$quote = $board['quote'];
$perf = $board['performance'];
$health = $board['health'];
$telegram = $board['telegram'];

$changeTone = match ($quote['direction'] ?? 'FLAT') {
    'UP'    => 'bull',
    'DOWN'  => 'bear',
    default => null,
};
?>

<div x-data="overview" x-init="start" class="space-y-6">

    <!-- Greeting + overall health -->
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink-100">
                Welcome back<?= $authUser !== null ? ', ' . e(explode(' ', $authUser->name)[0]) : '' ?>
            </h2>
            <p class="mt-1 text-sm text-ink-400">
                <?= e($board['instrument']['symbol']) ?> · <?= e($board['instrument']['name']) ?>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <?= $this->partial('partials.status-pill', ['status' => $health['status']]) ?>
            <?php if ($health['failing'] !== []): ?>
                <a href="/health" class="text-xs text-ink-400 underline decoration-dotted hover:text-ink-100">
                    <?= e(implode(', ', array_slice($health['failing'], 0, 2))) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Headline tiles -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php if ($quote['available']): ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Gold price',
                'value' => number_format((float) $quote['price'], 2),
                'sub'   => $quote['change_absolute'] === null
                    ? null
                    : sprintf(
                        '%s%s (%s%%)',
                        (float) $quote['change_absolute'] >= 0 ? '+' : '',
                        number_format((float) $quote['change_absolute'], 2),
                        number_format((float) ($quote['change_percent'] ?? 0), 2)
                    ),
                'tone'  => $changeTone,
                'age'   => $quote['age'],
            ]) ?>
        <?php else: ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Gold price',
                'value' => '—',
                'sub'   => 'No quote captured yet',
                'age'   => $quote['age'],
            ]) ?>
        <?php endif; ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Open signals',
            'value' => (string) $board['open_count'],
            'sub'   => 'Pending, active or risk-free',
            'tone'  => $board['open_count'] > 0 ? 'gold' : null,
        ]) ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Net R · 30 days',
            'value' => ($perf['net_r'] >= 0 ? '+' : '') . number_format($perf['net_r'], 2),
            'sub'   => $perf['total'] === 0
                ? 'No closed signals yet'
                : sprintf('%d closed · %s%% won', $perf['total'], $perf['win_rate'] ?? 0),
            'tone'  => $perf['net_r'] > 0 ? 'bull' : ($perf['net_r'] < 0 ? 'bear' : null),
        ]) ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Expectancy',
            // The number that actually answers "is running this worth it?".
            // A 40% win rate at 3R beats a 70% win rate at 0.4R, and only
            // this figure shows it.
            'value' => $perf['expectancy_r'] === null ? '—' : number_format($perf['expectancy_r'], 3) . 'R',
            'sub'   => 'Average R per closed signal',
            'tone'  => $perf['expectancy_r'] === null
                ? null
                : ($perf['expectancy_r'] > 0 ? 'bull' : 'bear'),
        ]) ?>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        <!-- Open signals -->
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Open signals</h3>
                <a href="/signals" class="text-xs text-gold-400 hover:text-gold-300">View all</a>
            </div>

            <?php if ($board['open'] === []): ?>
                <?= $this->partial('partials.empty', [
                    'message' => 'No open signals',
                    'detail'  => 'The engine publishes a signal only when a strategy clears its score '
                        . 'threshold and every filter passes. Nothing open is a normal state.',
                ]) ?>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                    <?php foreach ($board['open'] as $signal): ?>
                        <?= $this->partial('partials.signal-card', ['signal' => $signal]) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sessions + next event -->
        <div class="space-y-4">
            <div class="card p-5">
                <h3 class="mb-4 text-sm font-semibold text-ink-100">Trading sessions</h3>
                <ul class="space-y-3">
                    <?php foreach ($board['sessions'] as $session): ?>
                        <li class="flex items-center gap-3">
                            <span class="dot <?= $session['active'] ? 'bg-bull-500' : 'bg-base-600' ?>"
                                  aria-hidden="true"></span>
                            <span class="flex-1 text-sm <?= $session['active'] ? 'text-ink-100' : 'text-ink-500' ?>">
                                <?= e($session['name']) ?>
                            </span>
                            <span class="num text-xs text-ink-500">
                                <?= e(substr($session['open'], 0, 5)) ?>–<?= e(substr($session['close'], 0, 5)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="card p-5">
                <h3 class="mb-3 text-sm font-semibold text-ink-100">Next high-impact release</h3>
                <?php if ($board['next_event'] === null): ?>
                    <p class="text-sm text-ink-500">Nothing scheduled in the imported window.</p>
                <?php else: ?>
                    <p class="text-sm text-ink-100"><?= e($board['next_event']['title']) ?></p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="badge badge-bear"><?= e($board['next_event']['currency']) ?></span>
                        <span class="num text-xs text-ink-400">
                            <?= e($board['next_event']['countdown']) ?>
                        </span>
                        <?php if ($board['next_event']['approximate']): ?>
                            <span class="badge badge-neutral" title="The feed gave no exact time.">approx</span>
                        <?php endif; ?>
                    </div>
                    <p class="mt-3 text-xs text-ink-500">
                        Signal generation is suppressed inside this event's blackout window.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Timeframe strip -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Market structure</h3>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 font-medium">Timeframe</th>
                        <th class="px-5 py-3 font-medium">Trend</th>
                        <th class="px-5 py-3 text-right font-medium">Close</th>
                        <th class="px-5 py-3 text-right font-medium">EMA 50</th>
                        <th class="px-5 py-3 text-right font-medium">EMA 200</th>
                        <th class="px-5 py-3 text-right font-medium">RSI</th>
                        <th class="px-5 py-3 font-medium">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($board['timeframes'] as $row): ?>
                        <?php
                        $trendTone = match ($row['trend']) {
                            'UPTREND'   => 'text-bull-400',
                            'DOWNTREND' => 'text-bear-400',
                            default     => 'text-ink-400',
                        };
                        ?>
                        <tr class="border-b border-base-800 last:border-0">
                            <td class="px-5 py-3 font-medium text-ink-100"><?= e($row['code']) ?></td>
                            <td class="px-5 py-3 <?= $trendTone ?>">
                                <?= e(ucwords(strtolower(str_replace('_', ' ', $row['trend'])))) ?>
                            </td>
                            <td class="num px-5 py-3 text-right text-ink-200">
                                <?= $row['close'] === null ? '—' : e(number_format($row['close'], 2)) ?>
                            </td>
                            <td class="num px-5 py-3 text-right text-ink-400">
                                <?= $row['ema50'] === null ? '—' : e(number_format($row['ema50'], 2)) ?>
                            </td>
                            <td class="num px-5 py-3 text-right text-ink-400">
                                <?= $row['ema200'] === null ? '—' : e(number_format($row['ema200'], 2)) ?>
                            </td>
                            <td class="num px-5 py-3 text-right text-ink-400">
                                <?= $row['rsi'] === null ? '—' : e(number_format($row['rsi'], 1)) ?>
                            </td>
                            <td class="px-5 py-3">
                                <?= $this->partial('partials.data-age', ['age' => $row['age']]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Operations strip -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= $this->partial('partials.stat', [
            'label' => 'Telegram queue',
            'value' => (string) $telegram['pending'],
            'sub'   => $telegram['configured']
                ? sprintf('%d sent in 24h · %d failed', $telegram['sent_24h'], $telegram['failed'])
                : 'No bot token — messages queue but never send',
            'tone'  => $telegram['health'] === 'OK' ? null : ($telegram['health'] === 'WARNING' ? 'warn' : 'bear'),
        ]) ?>

        <?php foreach (array_slice($board['providers'], 0, 2) as $provider): ?>
            <?= $this->partial('partials.stat', [
                'label' => $provider['name'] . ' quota',
                'value' => $provider['percent_used'] === null
                    ? (string) $provider['calls_today']
                    : number_format($provider['percent_used'], 0) . '%',
                'sub'   => $provider['daily_limit'] === null
                    ? sprintf('%d calls today', $provider['calls_today'])
                    : sprintf('%d of %d credits', $provider['credits_today'], $provider['daily_limit']),
                'tone'  => $provider['status'] === 'OK' ? null : ($provider['status'] === 'WARNING' ? 'warn' : 'bear'),
                'age'   => $provider['age'],
            ]) ?>
        <?php endforeach; ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Max drawdown · 30d',
            'value' => '-' . number_format($perf['max_drawdown_r'], 2) . 'R',
            // Measured from the running peak of the equity curve: what the
            // system actually put you through, not where it finished.
            'sub'   => 'Peak-to-trough, in R',
            'tone'  => $perf['max_drawdown_r'] > 0 ? 'bear' : null,
        ]) ?>
    </div>

    <p class="text-center text-xs text-ink-500">
        Generated <time datetime="<?= e($board['generated_at']) ?>"><?= e($board['generated_at']) ?></time>
        · every figure above is read from the local database
    </p>
</div>
