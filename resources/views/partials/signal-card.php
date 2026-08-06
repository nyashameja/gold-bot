<?php
/**
 * One open signal, as a card.
 *
 * Shared by the Overview strip and the Signals page so the two cannot drift.
 *
 * @var array<string,mixed> $signal
 */
$tone = $signal['is_buy'] ? 'bull' : 'bear';
$hitTargets = count(array_filter($signal['targets'], static fn (array $t): bool => $t['hit']));
?>
<a href="/signals/<?= e($signal['uuid']) ?>"
   class="card card-hover block p-4 transition hover:border-base-600">

    <div class="flex flex-wrap items-center gap-2">
        <span class="badge badge-<?= $tone ?>">
            <?= $signal['is_buy'] ? 'Buy' : 'Sell' ?>
        </span>
        <?= $this->partial('partials.status-pill', [
            'status' => $signal['state'],
            'label'  => $signal['state_label'],
        ]) ?>
        <span class="badge badge-neutral"><?= e($signal['timeframe']) ?></span>
        <span class="ml-auto num text-sm font-semibold text-gold-400">
            <?= e(number_format($signal['score'], 1)) ?>
        </span>
    </div>

    <dl class="mt-3 grid grid-cols-3 gap-3 text-xs">
        <div>
            <dt class="text-ink-500">Entry</dt>
            <dd class="num mt-0.5 text-ink-100"><?= e(number_format($signal['entry'], 2)) ?></dd>
        </div>
        <div>
            <dt class="text-ink-500">Stop</dt>
            <dd class="num mt-0.5 text-bear-400"><?= e(number_format($signal['stop'], 2)) ?></dd>
        </div>
        <div>
            <dt class="text-ink-500">Targets</dt>
            <dd class="num mt-0.5 text-ink-300">
                <?= e($hitTargets . '/' . count($signal['targets'])) ?>
            </dd>
        </div>
    </dl>

    <div class="mt-3 flex items-center justify-between gap-2">
        <span class="truncate text-xs text-ink-500"><?= e($signal['strategy']) ?></span>
        <?= $this->partial('partials.data-age', ['age' => $signal['age']]) ?>
    </div>
</a>
