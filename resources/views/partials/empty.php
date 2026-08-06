<?php
/**
 * Empty state.
 *
 * Says WHY there is nothing here, not just that there is nothing. "No signals
 * yet" leaves an operator wondering whether the engine is broken; "the 714
 * strategy is disabled" tells them what to do about it.
 *
 * @var string      $message
 * @var string|null $detail
 * @var string|null $icon   An SVG path, defaulting to a neutral glyph.
 */
$icon = $icon ?? '<path d="M4 20V10M10 20V4M16 20v-7M22 20v-3"/>';
?>
<div class="flex flex-col items-center justify-center px-6 py-12 text-center">
    <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-base-800">
        <svg viewBox="0 0 24 24" class="h-5 w-5 text-ink-500" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <?= $icon ?>
        </svg>
    </div>
    <p class="text-sm text-ink-400"><?= e($message) ?></p>
    <?php if (!empty($detail)): ?>
        <p class="mt-1 max-w-sm text-xs text-ink-500"><?= e($detail) ?></p>
    <?php endif; ?>
</div>
