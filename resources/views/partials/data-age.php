<?php
/**
 * How old a displayed value is.
 *
 * Rendered beside every number that a cron refreshes. A price with no age
 * beside it looks live whether the feed died a second ago or yesterday, and
 * that is the single most misleading thing a trading interface can do
 * (docs/01 §8).
 *
 * @var array{at:string|null,seconds:int|null,status:string,label:string} $age
 * @var string|null $prefix
 */
$tone = match ($age['status']) {
    'FRESH' => 'text-ink-500',
    'STALE' => 'text-warn-400',
    'DEAD'  => 'text-bear-400',
    default => 'text-ink-500',
};

$dot = match ($age['status']) {
    'FRESH' => 'bg-bull-500',
    'STALE' => 'bg-warn-400',
    'DEAD'  => 'bg-bear-500',
    default => 'bg-base-600',
};

// The status word is spelled out for anything that is not fresh, so the
// meaning does not rest on colour alone.
$suffix = match ($age['status']) {
    'STALE' => ' · stale',
    'DEAD'  => ' · not updating',
    'NONE'  => '',
    default => '',
};
?>
<span class="inline-flex items-center gap-1.5 text-xs <?= $tone ?>"
      <?= $age['at'] !== null ? 'title="' . e((string) $age['at']) . '"' : '' ?>>
    <span class="dot <?= $dot ?>" aria-hidden="true"></span>
    <?php if (isset($prefix) && $prefix !== null): ?><?= e($prefix) ?> <?php endif; ?>
    <?php if ($age['at'] !== null): ?>
        <time datetime="<?= e((string) $age['at']) ?>"><?= e($age['label']) ?></time><?= e($suffix) ?>
    <?php else: ?>
        no data
    <?php endif; ?>
</span>
