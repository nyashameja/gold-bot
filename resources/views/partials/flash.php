<?php
/**
 * Flash messages.
 *
 * Read and cleared by ShareViewData, so a message cannot leak into a second
 * page because a template forgot to consume it.
 *
 * @var array<string,mixed> $flash
 */
$types = [
    'success' => ['border-bull-500/30',  'text-bull-400',  'bg-bull-500/10',  'm9 12 2 2 4-4'],
    'error'   => ['border-bear-500/30',  'text-bear-400',  'bg-bear-500/10',  'M12 8v5M12 16v.5'],
    'warning' => ['border-warn-400/30',  'text-warn-400',  'bg-warn-400/10',  'M12 8v5M12 16v.5'],
    'info'    => ['border-info-400/30',  'text-info-400',  'bg-info-400/10',  'M12 11v5M12 8v.5'],
];
?>
<?php foreach ($types as $type => [$border, $text, $bg, $path]): ?>
    <?php if (!empty($flash[$type]) && is_string($flash[$type])): ?>
        <div class="mb-5 flex items-start gap-3 rounded-xl border <?= $border ?> <?= $bg ?> px-4 py-3"
             role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 <?= $text ?>" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="<?= $path ?>"/>
            </svg>
            <p class="text-sm <?= $text ?>"><?= e($flash[$type]) ?></p>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
