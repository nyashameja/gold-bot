<?php
/**
 * Error page.
 *
 * Shows only the status and a safe message. Nothing internal — no paths, no
 * class names, no trace (docs/01 §10).
 *
 * @var int    $status
 * @var string $message
 */
$title = 'Error ' . $status;
?>
<div class="card p-8 text-center">
    <div class="mb-2 font-mono text-5xl font-semibold text-gold-500"><?= e((string) $status) ?></div>

    <h1 class="mb-2 text-lg font-semibold text-ink-100">
        <?= e(match ($status) {
            403     => 'Access denied',
            404     => 'Page not found',
            405     => 'Method not allowed',
            419     => 'Session expired',
            429     => 'Too many requests',
            default => 'Something went wrong',
        }) ?>
    </h1>

    <p class="mb-6 text-sm text-ink-400"><?= e($message) ?></p>

    <a href="/" class="btn btn-ghost w-full">Back to dashboard</a>
</div>
