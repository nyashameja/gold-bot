<?php
/**
 * Economic Calendar.
 *
 * Each event is annotated with whether it currently suppresses signal
 * generation, because that is the question an operator arrives with: "why did
 * nothing fire at 13:30?"
 *
 * @var array<string,mixed> $board
 */
$blackout = $board['blackout'];
$today = date('Y-m-d');
?>

<div class="space-y-6">

    <!-- Blackout banner -->
    <?php if (!$blackout['enabled']): ?>
        <div class="rounded-xl border border-warn-400/30 bg-warn-400/10 px-4 py-3">
            <p class="text-sm text-warn-400">
                The news filter is switched off. Signals will generate straight through high-impact
                releases.
            </p>
        </div>
    <?php elseif ($blackout['active'] && $blackout['event'] !== null): ?>
        <div class="rounded-xl border border-bear-500/30 bg-bear-500/10 px-4 py-3">
            <p class="text-sm text-bear-400">
                <strong>Blackout active</strong> — <?= e($blackout['event']['title']) ?>
                (<?= e($blackout['event']['currency']) ?>). No signal will be published until the window
                closes.
            </p>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" action="/calendar" class="card p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div>
                <label class="label" for="cal-back">Days back</label>
                <input id="cal-back" type="number" name="back" min="0" max="60" class="input"
                       value="<?= e((string) $board['window']['back']) ?>">
            </div>
            <div>
                <label class="label" for="cal-forward">Days forward</label>
                <input id="cal-forward" type="number" name="forward" min="1" max="60" class="input"
                       value="<?= e((string) $board['window']['forward']) ?>">
            </div>
            <div>
                <label class="label" for="cal-impact">Minimum impact</label>
                <select id="cal-impact" name="impact" class="input">
                    <option value="">All</option>
                    <?php foreach (['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"
                            <?= $board['window']['impact'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="btn btn-primary w-full">Apply</button>
            </div>
        </div>
    </form>

    <!-- Archive notice (ADR-15) -->
    <div class="card flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <p class="text-xs text-ink-500">
            <?php if ($board['archive']['starts_at'] === null): ?>
                No events archived yet. The free feeds publish a rolling window only, so history before
                the first import does not exist locally and never will.
            <?php else: ?>
                Local archive begins
                <time class="num text-ink-400"
                      datetime="<?= e($board['archive']['starts_at']) ?>"><?= e(substr($board['archive']['starts_at'], 0, 10)) ?></time>.
                A backtest that filters news over any earlier period would be silently unfiltered (ADR-15).
            <?php endif; ?>
        </p>
        <span class="num text-xs text-ink-400"><?= e((string) $board['archive']['total']) ?> events stored</span>
    </div>

    <!-- Days -->
    <?php if ($board['days'] === []): ?>
        <div class="card">
            <?= $this->partial('partials.empty', [
                'message' => 'No events in this window',
                'detail'  => 'Run php cron/run.php task calendar.import to fetch the current window.',
                'icon'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($board['days'] as $day): ?>
                <div class="card">
                    <div class="flex items-center justify-between border-b border-base-750 px-5 py-3">
                        <h3 class="text-sm font-semibold <?= $day['date'] === $today ? 'text-gold-400' : 'text-ink-100' ?>">
                            <?= e(date('l, j F Y', strtotime($day['date']))) ?>
                            <?php if ($day['date'] === $today): ?>
                                <span class="badge badge-gold ml-2">Today</span>
                            <?php endif; ?>
                        </h3>
                        <span class="num text-xs text-ink-500"><?= e((string) count($day['events'])) ?> events</span>
                    </div>

                    <div class="table-scroll">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                    <th class="px-5 py-3 font-medium">Time</th>
                                    <th class="px-5 py-3 font-medium">Cur</th>
                                    <th class="px-5 py-3 font-medium">Impact</th>
                                    <th class="px-5 py-3 font-medium">Event</th>
                                    <th class="px-5 py-3 text-right font-medium">Actual</th>
                                    <th class="px-5 py-3 text-right font-medium">Forecast</th>
                                    <th class="px-5 py-3 text-right font-medium">Previous</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($day['events'] as $event): ?>
                                    <?php
                                    $impactClass = match ($event['impact']) {
                                        'HIGH'    => 'badge-bear',
                                        'MEDIUM'  => 'badge-neutral text-warn-400 border-warn-400/30',
                                        'HOLIDAY' => 'badge-gold',
                                        default   => 'badge-neutral',
                                    };
                                    $surpriseTone = match ($event['surprise']) {
                                        'ABOVE' => 'text-bull-400',
                                        'BELOW' => 'text-bear-400',
                                        default => 'text-ink-200',
                                    };
                                    ?>
                                    <tr class="border-b border-base-800 last:border-0
                                               <?= $event['blackout']['active'] ? 'bg-bear-500/5' : '' ?>">
                                        <td class="num px-5 py-3 text-ink-300">
                                            <?= e($event['time']) ?>
                                            <?php if ($event['approximate']): ?>
                                                <span class="text-ink-500"
                                                      title="The feed gave no exact time; the blackout window is widened.">~</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-ink-400"><?= e($event['currency']) ?></td>
                                        <td class="px-5 py-3">
                                            <span class="badge <?= $impactClass ?>"><?= e($event['impact_label']) ?></span>
                                        </td>
                                        <td class="px-5 py-3 text-ink-100">
                                            <?= e($event['title']) ?>
                                            <?php if ($event['blackout']['active']): ?>
                                                <span class="badge badge-bear ml-2">Blackout now</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num px-5 py-3 text-right <?= $surpriseTone ?>">
                                            <?= e($event['actual'] ?? '—') ?>
                                        </td>
                                        <td class="num px-5 py-3 text-right text-ink-400"><?= e($event['forecast'] ?? '—') ?></td>
                                        <td class="num px-5 py-3 text-right text-ink-500"><?= e($event['previous'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="flex justify-end">
        <?= $this->partial('partials.data-age', ['age' => $board['age'], 'prefix' => 'Calendar imported']) ?>
    </div>
</div>
