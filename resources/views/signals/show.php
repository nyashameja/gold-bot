<?php
/**
 * A single signal.
 *
 * Three things are shown together, and they have to be: the plan (entry, stop,
 * targets), the reasoning (pillar scores), and the record (the event log). The
 * event log is the source of truth and the state is a projection of it
 * (ADR-05); showing both is what makes a surprising outcome explainable months
 * later instead of a mystery.
 *
 * @var array<string,mixed> $signal
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
$canCancel = $authUser?->can('signals.cancel') ?? false;
$tone = $signal['is_buy'] ? 'bull' : 'bear';
?>

<div class="space-y-6">

    <a href="/signals" class="inline-flex items-center gap-1.5 text-xs text-ink-400 hover:text-ink-100">
        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m15 18-6-6 6-6"/>
        </svg>
        All signals
    </a>

    <!-- Header -->
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge badge-<?= $tone ?>"><?= $signal['is_buy'] ? 'Buy' : 'Sell' ?></span>
                    <?= $this->partial('partials.status-pill', [
                        'status' => $signal['state'],
                        'label'  => $signal['state_label'],
                    ]) ?>
                    <span class="badge badge-neutral"><?= e($signal['timeframe']) ?></span>
                    <span class="badge badge-gold"><?= e($signal['strategy']) ?></span>
                </div>
                <p class="num mt-3 text-xs text-ink-500"><?= e($signal['uuid']) ?></p>
            </div>

            <div class="text-right">
                <div class="stat-label">Score</div>
                <div class="stat-value text-gold-400"><?= e(number_format($signal['score'], 1)) ?></div>
            </div>
        </div>

        <?php if ($canCancel && $signal['is_open']): ?>
            <form method="post" action="/signals/<?= e($signal['uuid']) ?>/cancel" class="mt-5 flex flex-wrap gap-2">
                <?= $csrf->field() ?>
                <input type="text" name="reason" class="input flex-1 sm:max-w-sm"
                       placeholder="Reason (recorded in the event log)">
                <button type="submit" class="btn btn-ghost text-bear-400">Cancel signal</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Plan -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= $this->partial('partials.stat', [
            'label' => 'Entry',
            'value' => number_format($signal['entry'], 2),
            'sub'   => $signal['activated_at'] === null ? 'Not yet filled' : 'Filled ' . $signal['activated_at'],
        ]) ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Stop loss',
            'value' => number_format($signal['stop'], 2),
            'sub'   => sprintf('%s risk distance', number_format($signal['risk_distance'], 2)),
            'tone'  => 'bear',
        ]) ?>

        <?= $this->partial('partials.stat', [
            'label' => 'Risk / reward',
            'value' => $signal['risk_reward'] === null ? '—' : number_format($signal['risk_reward'], 2) . ':1',
            'sub'   => 'At the final target',
        ]) ?>

        <?php if ($signal['realised_r'] !== null): ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Realised',
                'value' => ($signal['realised_r'] > 0 ? '+' : '') . number_format($signal['realised_r'], 2) . 'R',
                'sub'   => $signal['close_reason'] === null
                    ? null
                    : ucwords(strtolower(str_replace('_', ' ', $signal['close_reason']))),
                'tone'  => $signal['realised_r'] > 0 ? 'bull' : ($signal['realised_r'] < 0 ? 'bear' : null),
            ]) ?>
        <?php elseif ($signal['unrealised'] !== null): ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Unrealised',
                'value' => ($signal['unrealised']['r'] > 0 ? '+' : '') . number_format($signal['unrealised']['r'], 2) . 'R',
                'sub'   => 'Marked at ' . number_format((float) $signal['last_price'], 2),
                'tone'  => $signal['unrealised']['r'] > 0 ? 'bull' : ($signal['unrealised']['r'] < 0 ? 'bear' : null),
                'age'   => $signal['price_age'],
            ]) ?>
        <?php else: ?>
            <?= $this->partial('partials.stat', [
                'label' => 'Outcome',
                'value' => '—',
                'sub'   => 'No price available to mark against',
            ]) ?>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Targets -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Targets</h3>
            </div>
            <?php if ($signal['targets'] === []): ?>
                <?= $this->partial('partials.empty', ['message' => 'This signal has no targets']) ?>
            <?php else: ?>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 font-medium">Level</th>
                            <th class="px-5 py-3 text-right font-medium">Price</th>
                            <th class="px-5 py-3 text-right font-medium">R</th>
                            <th class="px-5 py-3 text-right font-medium">Close</th>
                            <th class="px-5 py-3 font-medium">Hit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($signal['targets'] as $target): ?>
                            <tr class="border-b border-base-800 last:border-0">
                                <td class="px-5 py-3 text-ink-100">TP<?= e((string) $target['level']) ?></td>
                                <td class="num px-5 py-3 text-right text-ink-200">
                                    <?= e(number_format($target['price'], 2)) ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= $target['r_multiple'] === null ? '—' : e(number_format($target['r_multiple'], 1) . 'R') ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= $target['close_percent'] === null ? '—' : e(number_format($target['close_percent'], 0) . '%') ?>
                                </td>
                                <td class="px-5 py-3">
                                    <?php if ($target['hit']): ?>
                                        <span class="badge badge-bull">
                                            <span class="dot bg-bull-500" aria-hidden="true"></span>
                                            <?= e(substr((string) $target['hit_at'], 0, 16)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-ink-500">Not reached</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Pillar scores -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Why this scored what it did</h3>
            </div>
            <?php if ($signal['scores'] === []): ?>
                <?= $this->partial('partials.empty', ['message' => 'No pillar scores recorded']) ?>
            <?php else: ?>
                <ul class="divide-y divide-base-800">
                    <?php foreach ($signal['scores'] as $pillar): ?>
                        <li class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="flex-1 text-sm text-ink-100">
                                    <?= e(ucwords(strtolower(str_replace('_', ' ', $pillar['pillar'])))) ?>
                                </span>
                                <?php if (!$pillar['passed']): ?>
                                    <span class="badge badge-bear">Gate failed</span>
                                <?php endif; ?>
                                <span class="num text-sm text-ink-300">
                                    <?= e(number_format($pillar['raw'], 0)) ?>
                                    <span class="text-ink-500">× <?= e(number_format($pillar['weight'], 0)) ?>%</span>
                                </span>
                            </div>

                            <!-- Bar carries the same information as the number, for scanning. -->
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-base-800">
                                <div class="h-full rounded-full <?= $pillar['passed'] ? 'bg-gold-500' : 'bg-bear-500' ?>"
                                     style="width: <?= e((string) max(0, min(100, $pillar['raw']))) ?>%"></div>
                            </div>

                            <?php if ($pillar['detail'] !== []): ?>
                                <dl class="mt-2 space-y-1">
                                    <?php foreach ($pillar['detail'] as $key => $value): ?>
                                        <div class="flex items-start gap-2 text-xs">
                                            <dt class="text-ink-500"><?= e((string) $key) ?></dt>
                                            <dd class="num flex-1 text-right text-ink-400">
                                                <?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?>
                                            </dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event log -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Event log</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                Append-only. The state above is a projection of these events, not the other way round.
            </p>
        </div>
        <ol class="divide-y divide-base-800">
            <?php foreach ($signal['events'] as $event): ?>
                <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <span class="dot bg-gold-500" aria-hidden="true"></span>
                    <span class="flex-1 text-sm text-ink-200"><?= e($event['label']) ?></span>
                    <?php if ($event['price'] !== null): ?>
                        <span class="num text-xs text-ink-400"><?= e(number_format($event['price'], 2)) ?></span>
                    <?php endif; ?>
                    <?php if ($event['triggered_by'] !== 'SYSTEM'): ?>
                        <span class="badge badge-neutral"><?= e(strtolower($event['triggered_by'])) ?></span>
                    <?php endif; ?>
                    <span class="num text-xs text-ink-500"><?= e(substr($event['at'], 0, 19)) ?></span>
                    <?php if ($event['notes'] !== null): ?>
                        <p class="w-full text-xs text-ink-500"><?= e($event['notes']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
