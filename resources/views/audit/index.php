<?php
/**
 * Audit trail.
 *
 * @var list<array<string,mixed>> $entries
 * @var int                       $page
 */
$title = 'Audit Log';

$tone = static fn (string $action): string => match (true) {
    str_starts_with($action, 'auth.login')  => 'badge-bull',
    str_starts_with($action, 'auth.logout') => 'badge-neutral',
    str_contains($action, 'delete'),
    str_contains($action, 'cancel')         => 'badge-bear',
    default                                 => 'badge-gold',
};
?>

<div class="mb-6">
    <p class="text-sm text-ink-400">
        Every privileged action, appended and never edited. Retained indefinitely.
    </p>
</div>

<?php if ($entries === []): ?>
    <div class="card p-12 text-center">
        <p class="text-sm text-ink-400">No audit entries yet.</p>
    </div>
<?php else: ?>
    <div class="card overflow-hidden">
        <!-- The table scrolls inside its own container so the page body never
             scrolls horizontally on a phone. -->
        <div class="table-scroll">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-xs uppercase tracking-wider text-ink-500">
                        <th scope="col" class="px-5 py-3 font-medium">When (UTC)</th>
                        <th scope="col" class="px-5 py-3 font-medium">Action</th>
                        <th scope="col" class="px-5 py-3 font-medium">Actor</th>
                        <th scope="col" class="px-5 py-3 font-medium">Subject</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr class="border-b border-base-850 last:border-0 transition hover:bg-base-850/60">
                            <td class="num whitespace-nowrap px-5 py-3 text-xs text-ink-400">
                                <?= e((string) $entry['created_at']) ?>
                            </td>
                            <td class="px-5 py-3">
                                <span class="badge <?= $tone((string) $entry['action']) ?>">
                                    <?= e((string) $entry['action']) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-ink-300">
                                <?= e((string) ($entry['user_name'] ?? 'System')) ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-ink-500">
                                <?= $entry['subject_type'] !== null
                                    ? e($entry['subject_type'] . ' #' . $entry['subject_id'])
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 flex items-center justify-between">
        <span class="text-xs text-ink-500">Page <?= e((string) $page) ?></span>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
                <a href="/audit?page=<?= e((string) ($page - 1)) ?>" class="btn btn-ghost">Previous</a>
            <?php endif; ?>
            <?php if (count($entries) === 50): ?>
                <a href="/audit?page=<?= e((string) ($page + 1)) ?>" class="btn btn-ghost">Next</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
