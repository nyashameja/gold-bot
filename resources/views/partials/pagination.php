<?php
/**
 * Pagination.
 *
 * Prev/next rather than numbered pages. The signals table is ordered newest
 * first and grows forever, so page 47 is not a place anyone means to go — and
 * numbered links would need a COUNT on every render to know how many to draw.
 *
 * @var array{current:int,last:int,total:int,from:int,to:int,has_prev:bool,has_next:bool} $page
 * @var array<string,mixed> $query Current filters, preserved across pages.
 * @var string $path
 */
$link = static function (int $target) use ($query, $path): string {
    return $path . '?' . http_build_query([...$query, 'page' => $target]);
};
?>
<?php if ($page['total'] > 0): ?>
    <div class="flex flex-col items-center justify-between gap-3 border-t border-base-750 px-4 py-3 sm:flex-row">
        <p class="text-xs text-ink-500">
            Showing <span class="num text-ink-400"><?= e((string) $page['from']) ?></span>–<span
                class="num text-ink-400"><?= e((string) $page['to']) ?></span>
            of <span class="num text-ink-400"><?= e((string) $page['total']) ?></span>
        </p>

        <div class="flex items-center gap-2">
            <?php if ($page['has_prev']): ?>
                <a href="<?= e($link($page['current'] - 1)) ?>" class="btn btn-ghost" rel="prev">Previous</a>
            <?php else: ?>
                <span class="btn btn-ghost cursor-not-allowed opacity-40" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <span class="px-2 text-xs text-ink-500">
                <?= e((string) $page['current']) ?> / <?= e((string) $page['last']) ?>
            </span>

            <?php if ($page['has_next']): ?>
                <a href="<?= e($link($page['current'] + 1)) ?>" class="btn btn-ghost" rel="next">Next</a>
            <?php else: ?>
                <span class="btn btn-ghost cursor-not-allowed opacity-40" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
