<?php
/**
 * An OK / WARNING / CRITICAL pill.
 *
 * The word is always present. Colour carries the same meaning for speed, but
 * never carries it alone — roughly one man in twelve cannot reliably tell the
 * green from the red.
 *
 * @var string      $status
 * @var string|null $label
 */
[$classes, $dot] = match (strtoupper($status)) {
    'OK', 'SUCCESS', 'SENT'      => ['badge-bull', 'bg-bull-500'],
    'WARNING', 'PENDING', 'FAILED' => ['badge-neutral text-warn-400 border-warn-400/30', 'bg-warn-400'],
    'CRITICAL', 'DEAD', 'ERROR'  => ['badge-bear', 'bg-bear-500'],
    default                      => ['badge-neutral', 'bg-base-600'],
};
?>
<span class="badge <?= $classes ?>">
    <span class="dot <?= $dot ?>" aria-hidden="true"></span>
    <?= e($label ?? ucfirst(strtolower($status))) ?>
</span>
