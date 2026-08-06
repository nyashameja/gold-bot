<?php
/**
 * API Usage.
 *
 * Both providers are on free tiers with hard daily quotas, so exhausting one
 * means no market data until midnight. The projection column is the point of
 * the page: where today's consumption lands if the current rate holds. It is
 * the only version of this number that arrives in time to act on.
 *
 * @var array<string,mixed> $board
 */
?>

<div x-data="apiUsage"
     data-series="<?= e(json_encode($board['series'], JSON_THROW_ON_ERROR)) ?>"
     x-init="start"
     class="space-y-6">

    <!-- Providers -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <?php foreach ($board['providers'] as $provider): ?>
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-ink-100"><?= e($provider['name']) ?></h3>
                            <?= $this->partial('partials.status-pill', ['status' => $provider['status']]) ?>
                            <?php if (!$provider['active']): ?>
                                <span class="badge badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <p class="num mt-1 text-xs text-ink-500"><?= e($provider['code']) ?></p>
                    </div>
                    <?= $this->partial('partials.data-age', ['age' => $provider['age'], 'prefix' => 'Last call']) ?>
                </div>

                <?php if ($provider['daily_limit'] !== null): ?>
                    <?php $percent = min(100.0, (float) $provider['percent_used']); ?>
                    <div class="mt-4">
                        <div class="mb-1.5 flex items-baseline justify-between">
                            <span class="num text-2xl font-semibold text-ink-100">
                                <?= e(number_format((float) $provider['percent_used'], 1)) ?>%
                            </span>
                            <span class="num text-xs text-ink-500">
                                <?= e(number_format($provider['credits_today'])) ?> /
                                <?= e(number_format($provider['daily_limit'])) ?> credits
                            </span>
                        </div>
                        <div class="relative h-2.5 w-full overflow-hidden rounded-full bg-base-850">
                            <div class="h-full rounded-full <?= $percent >= 90 ? 'bg-bear-500' : ($percent >= 70 ? 'bg-warn-400' : 'bg-gold-500') ?>"
                                 style="width: <?= e((string) $percent) ?>%"></div>

                            <?php if ($provider['projected_percent'] !== null): ?>
                                <!-- Projection marker: where today ends at the current rate. -->
                                <span class="absolute top-0 h-full w-0.5 bg-ink-100/70"
                                      style="left: <?= e((string) min(100.0, (float) $provider['projected_percent'])) ?>%"
                                      title="Projected end of day: <?= e(number_format((float) $provider['projected_percent'], 0)) ?>%"></span>
                            <?php endif; ?>
                        </div>

                        <p class="mt-2 text-xs <?= ($provider['projected_percent'] ?? 0) >= 100 ? 'text-bear-400' : 'text-ink-500' ?>">
                            <?php if ($provider['projected_percent'] === null): ?>
                                Too early in the day to project a rate.
                            <?php elseif ($provider['projected_percent'] >= 100): ?>
                                On track to exhaust the quota before midnight
                                (<?= e(number_format((float) $provider['projected_percent'], 0)) ?>% projected).
                            <?php else: ?>
                                Projected <?= e(number_format((float) $provider['projected_percent'], 0)) ?>% by
                                midnight · <?= e(number_format((int) $provider['remaining'])) ?> credits spare.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-4">
                        <span class="num text-2xl font-semibold text-ink-100">
                            <?= e(number_format($provider['calls_today'])) ?>
                        </span>
                        <p class="mt-1 text-xs text-ink-500">calls today · no published quota</p>
                    </div>
                <?php endif; ?>

                <dl class="mt-4 grid grid-cols-3 gap-3 border-t border-base-800 pt-4 text-xs">
                    <div>
                        <dt class="text-ink-500">Last minute</dt>
                        <dd class="num mt-0.5 text-ink-200">
                            <?= e((string) $provider['calls_last_minute']) ?>
                            <?php if ($provider['per_minute_limit'] !== null): ?>
                                <span class="text-ink-500">/ <?= e((string) $provider['per_minute_limit']) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Failures · 1h</dt>
                        <dd class="num mt-0.5 <?= $provider['failures_last_hour'] > 0 ? 'text-bear-400' : 'text-ink-200' ?>">
                            <?= e((string) $provider['failures_last_hour']) ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Avg latency</dt>
                        <dd class="num mt-0.5 text-ink-200">
                            <?= $provider['avg_ms_last_hour'] === null ? '—' : e($provider['avg_ms_last_hour'] . 'ms') ?>
                        </dd>
                    </div>
                </dl>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Hourly chart -->
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Calls per hour</h3>
            <form method="get" action="/api-usage" class="flex items-center gap-2">
                <label class="sr-only" for="usage-hours">Window</label>
                <select id="usage-hours" name="hours" class="input !min-h-0 !py-1.5 text-xs">
                    <?php foreach ([24 => '24 hours', 48 => '48 hours', 168 => '7 days', 720 => '30 days'] as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>"
                            <?= $board['window']['hours'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost !min-h-0 !px-3 !py-1.5 text-xs">Apply</button>
            </form>
        </div>

        <?php if ($board['series'] === []): ?>
            <?= $this->partial('partials.empty', [
                'message' => 'No API calls in this window',
                'icon'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            ]) ?>
        <?php else: ?>
            <div class="p-4">
                <div class="relative h-[280px] w-full">
                    <canvas x-ref="usageChart" role="img" aria-label="API calls and failures per hour"></canvas>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <!-- Endpoints -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Heaviest endpoints</h3>
                <p class="mt-0.5 text-xs text-ink-500">By credits consumed in the window.</p>
            </div>
            <?php if ($board['endpoints'] === []): ?>
                <?= $this->partial('partials.empty', ['message' => 'Nothing called yet']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead>
                            <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                                <th class="px-5 py-2.5 font-medium">Endpoint</th>
                                <th class="px-5 py-2.5 text-right font-medium">Calls</th>
                                <th class="px-5 py-2.5 text-right font-medium">Credits</th>
                                <th class="px-5 py-2.5 text-right font-medium">Fails</th>
                                <th class="px-5 py-2.5 text-right font-medium">Avg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($board['endpoints'] as $endpoint): ?>
                                <tr class="border-b border-base-800 last:border-0">
                                    <td class="px-5 py-2.5">
                                        <span class="num text-ink-200"><?= e($endpoint['endpoint']) ?></span>
                                        <span class="ml-1 text-xs text-ink-500"><?= e($endpoint['provider']) ?></span>
                                    </td>
                                    <td class="num px-5 py-2.5 text-right text-ink-400"><?= e((string) $endpoint['calls']) ?></td>
                                    <td class="num px-5 py-2.5 text-right text-gold-400"><?= e((string) $endpoint['credits']) ?></td>
                                    <td class="num px-5 py-2.5 text-right <?= $endpoint['failures'] > 0 ? 'text-bear-400' : 'text-ink-500' ?>">
                                        <?= e((string) $endpoint['failures']) ?>
                                    </td>
                                    <td class="num px-5 py-2.5 text-right text-ink-500">
                                        <?= $endpoint['avg_ms'] === null ? '—' : e(number_format($endpoint['avg_ms'], 0) . 'ms') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Failures -->
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Recent failures</h3>
            </div>
            <?php if ($board['failures'] === []): ?>
                <?= $this->partial('partials.empty', [
                    'message' => 'No failed calls recorded',
                    'detail'  => 'Every provider request is logged, successful or not — an empty list here '
                        . 'means the integrations are healthy, not that logging is off.',
                ]) ?>
            <?php else: ?>
                <ul class="divide-y divide-base-800">
                    <?php foreach ($board['failures'] as $failure): ?>
                        <li class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <span class="badge badge-bear">
                                    <?= e($failure['status'] === null ? 'no response' : (string) $failure['status']) ?>
                                </span>
                                <span class="num flex-1 truncate text-sm text-ink-200"><?= e($failure['endpoint']) ?></span>
                                <span class="num text-xs text-ink-500"><?= e(substr($failure['at'], 0, 16)) ?></span>
                            </div>
                            <?php if ($failure['error'] !== null): ?>
                                <p class="mt-1 text-xs text-ink-500"><?= e($failure['error']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
