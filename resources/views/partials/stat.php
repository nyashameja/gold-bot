<?php
/**
 * A single statistic tile.
 *
 * @var string      $label
 * @var string      $value
 * @var string|null $sub    Small caption under the value.
 * @var string|null $tone   bull | bear | gold | warn | null
 * @var array|null  $age    A DataAge array, rendered under the caption.
 * @var string|null $badge
 */
$tone = $tone ?? null;

$valueTone = match ($tone) {
    'bull' => 'text-bull-400',
    'bear' => 'text-bear-400',
    'gold' => 'text-gold-400',
    'warn' => 'text-warn-400',
    default => 'text-ink-100',
};
?>
<div class="card card-hover p-5">
    <div class="flex items-start justify-between gap-3">
        <span class="stat-label"><?= e($label) ?></span>
        <?php if (!empty($badge)): ?>
            <span class="badge badge-<?= $tone === 'bull' ? 'bull' : ($tone === 'bear' ? 'bear' : 'neutral') ?>">
                <?= e($badge) ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="stat-value mt-3 <?= $valueTone ?>"><?= e($value) ?></div>

    <?php if (!empty($sub)): ?>
        <div class="mt-1 text-xs text-ink-500"><?= e($sub) ?></div>
    <?php endif; ?>

    <?php if (!empty($age)): ?>
        <div class="mt-2"><?= $this->partial('partials.data-age', ['age' => $age]) ?></div>
    <?php endif; ?>
</div>
