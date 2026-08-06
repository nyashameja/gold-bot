<?php
/**
 * Telegram.
 *
 * The number that matters is not queue depth but the age of the oldest waiting
 * message. Forty pending that drain every minute is healthy; two pending where
 * the older is an hour old means the drain cron has stopped — and depth alone
 * cannot tell those apart.
 *
 * @var array<string,mixed> $board
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
$queue = $board['queue'];
$canSend = $authUser?->can('telegram.send') ?? false;
?>

<div class="space-y-6">

    <?php if (!$board['configured']): ?>
        <div class="rounded-xl border border-warn-400/30 bg-warn-400/10 px-4 py-3">
            <p class="text-sm text-warn-400">
                No bot token is configured. Messages are still enqueued correctly — they simply will not
                send until <code class="num">TELEGRAM_BOT_TOKEN</code> is set in the environment. Nothing
                is lost in the meantime.
            </p>
        </div>
    <?php endif; ?>

    <!-- Queue -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= $this->partial('partials.stat', [
            'label' => 'Pending',
            'value' => (string) $queue['pending'],
            'sub'   => $queue['oldest_pending_label'] === null
                ? 'Queue empty'
                : 'Oldest waiting ' . $queue['oldest_pending_label'],
            'tone'  => $queue['health'] === 'OK' ? null : ($queue['health'] === 'WARNING' ? 'warn' : 'bear'),
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Sent · 24h',
            'value' => (string) $queue['sent_24h'],
            'sub'   => 'Delivered in the last day',
            'tone'  => $queue['sent_24h'] > 0 ? 'bull' : null,
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Failed',
            'value' => (string) $queue['failed'],
            'sub'   => 'Retryable — will be attempted again',
            'tone'  => $queue['failed'] > 0 ? 'warn' : null,
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Dead',
            'value' => (string) $queue['dead'],
            'sub'   => 'Attempts exhausted — needs a manual retry',
            'tone'  => $queue['dead'] > 0 ? 'bear' : null,
        ]) ?>
    </div>

    <!-- Messages -->
    <div class="card">
        <div class="flex items-center justify-between border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Recent messages</h3>
            <?= $this->partial('partials.data-age', ['age' => $board['age'], 'prefix' => 'Last send']) ?>
        </div>

        <?php if ($board['messages'] === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No messages queued yet',
                'detail'  => 'A message is enqueued in the same transaction as the signal that caused it, '
                    . 'so nothing appears here until a signal is published.',
                'icon'    => '<path d="m22 2-7 20-4-9-9-4z"/>',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 font-medium">Queued</th>
                            <th class="px-5 py-3 font-medium">Template</th>
                            <th class="px-5 py-3 font-medium">Chat</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 text-right font-medium">Attempts</th>
                            <th class="px-5 py-3 font-medium">Latency</th>
                            <?php if ($canSend): ?>
                                <th class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($board['messages'] as $message): ?>
                            <tr class="border-b border-base-800 last:border-0">
                                <td class="num px-5 py-3 text-xs text-ink-400">
                                    <?= e(str_replace('T', ' ', substr($message['created_at'], 0, 16))) ?>
                                </td>
                                <td class="px-5 py-3 text-ink-200"><?= e($message['template']) ?></td>
                                <td class="num px-5 py-3 text-xs text-ink-500"><?= e($message['chat_id']) ?></td>
                                <td class="px-5 py-3">
                                    <?= $this->partial('partials.status-pill', ['status' => $message['status']]) ?>
                                    <?php if ($message['last_error'] !== null): ?>
                                        <p class="mt-1 max-w-xs truncate text-xs text-bear-400"
                                           title="<?= e($message['last_error']) ?>">
                                            <?= e($message['last_error']) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td class="num px-5 py-3 text-right text-ink-400">
                                    <?= e($message['attempts'] . '/' . $message['max_attempts']) ?>
                                </td>
                                <td class="num px-5 py-3 text-xs text-ink-500"><?= e($message['latency'] ?? '—') ?></td>
                                <?php if ($canSend): ?>
                                    <td class="px-5 py-3 text-right">
                                        <?php if (in_array($message['status'], ['FAILED', 'DEAD'], true)): ?>
                                            <form method="post" action="/telegram/<?= e((string) $message['id']) ?>/retry">
                                                <?= $csrf->field() ?>
                                                <button type="submit" class="btn btn-ghost !min-h-0 !px-3 !py-1.5 text-xs">
                                                    Retry
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Chats -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Chats</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    Each chat subscribes to message classes independently, so operational alerts can be
                    routed away from a subscriber channel.
                </p>
            </div>
            <?php if ($board['chats'] === []): ?>
                <?= $this->partial('partials.empty', [
                    'message' => 'No chats registered',
                    'detail'  => 'Add a row to telegram_chats with the chat id the bot should post to.',
                ]) ?>
            <?php else: ?>
                <ul class="divide-y divide-base-800">
                    <?php foreach ($board['chats'] as $chat): ?>
                        <li class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <span class="dot <?= (int) $chat['is_active'] === 1 ? 'bg-bull-500' : 'bg-base-600' ?>"
                                      aria-hidden="true"></span>
                                <span class="flex-1 truncate text-sm text-ink-100">
                                    <?= e((string) ($chat['title'] ?? $chat['chat_id'])) ?>
                                </span>
                                <span class="badge badge-neutral"><?= e((string) $chat['type']) ?></span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <?php
                                // These are the three audiences MessageType
                                // routes to; there is no fourth.
                                $subscriptions = [
                                    'receives_signals'   => 'signals',
                                    'receives_summaries' => 'summaries',
                                    'receives_alerts'    => 'alerts',
                                ];
                                ?>
                                <?php foreach ($subscriptions as $column => $label): ?>
                                    <?php if ((int) ($chat[$column] ?? 0) === 1): ?>
                                        <span class="badge badge-gold"><?= e($label) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Templates -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Templates</h3>
            </div>
            <div class="table-scroll">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-2.5 font-medium">Code</th>
                            <th class="px-5 py-2.5 font-medium">Name</th>
                            <th class="px-5 py-2.5 font-medium">Mode</th>
                            <th class="px-5 py-2.5 font-medium">Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($board['templates'] as $template): ?>
                            <tr class="border-b border-base-800 last:border-0">
                                <td class="num px-5 py-2.5 text-xs text-ink-300"><?= e((string) $template['code']) ?></td>
                                <td class="px-5 py-2.5 text-ink-200"><?= e((string) $template['name']) ?></td>
                                <td class="px-5 py-2.5 text-xs text-ink-500"><?= e((string) $template['parse_mode']) ?></td>
                                <td class="px-5 py-2.5">
                                    <span class="dot <?= (int) $template['is_active'] === 1 ? 'bg-bull-500' : 'bg-base-600' ?>"
                                          aria-hidden="true"></span>
                                    <span class="sr-only"><?= (int) $template['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
