<?php
/**
 * Settings.
 *
 * Runtime values only. API keys and the bot token are environment variables
 * and never appear on this page — a setting flagged secret is rendered as a
 * mask and is only written when a replacement is actually typed, so re-saving
 * the form cannot overwrite a stored secret with the row of dots the browser
 * was showing.
 *
 * @var array<string,list<array<string,mixed>>> $groups
 * @var array<string,string> $errors
 */
$groupLabels = [
    'general'   => 'General',
    'market'    => 'Market data',
    'signals'   => 'Signal engine',
    'news'      => 'News filter',
    'telegram'  => 'Telegram',
    'risk'      => 'Risk',
    'system'    => 'System',
];
?>

<div class="space-y-6">

    <div class="rounded-xl border border-base-750 bg-base-900 px-4 py-3">
        <p class="text-xs text-ink-500">
            These are runtime values, changeable while the system runs. Credentials are not settings —
            they live in the environment and are never rendered here. Every change below is written to
            the audit log with its before and after value.
        </p>
    </div>

    <form method="post" action="/settings" class="space-y-6">
        <?= $csrf->field() ?>

        <?php foreach ($groups as $group => $settings): ?>
            <div class="card">
                <div class="border-b border-base-750 px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink-100">
                        <?= e($groupLabels[$group] ?? ucfirst($group)) ?>
                    </h3>
                </div>

                <div class="divide-y divide-base-800">
                    <?php foreach ($settings as $setting): ?>
                        <div class="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 sm:items-start">
                            <div>
                                <label class="text-sm font-medium text-ink-100"
                                       for="setting-<?= e($setting['key']) ?>">
                                    <?= e($setting['label']) ?>
                                </label>
                                <?php if ($setting['description'] !== null): ?>
                                    <p class="mt-1 text-xs text-ink-500"><?= e($setting['description']) ?></p>
                                <?php endif; ?>
                                <p class="num mt-1 text-xs text-ink-500"><?= e($setting['key']) ?></p>
                            </div>

                            <div>
                                <?php if ($setting['type'] === 'bool'): ?>
                                    <!--
                                      An unchecked box submits nothing, which would read as "no change".
                                      The hidden field ahead of it makes "off" explicit.
                                    -->
                                    <input type="hidden" name="settings[<?= e($setting['key']) ?>]" value="0">
                                    <label class="flex cursor-pointer items-center gap-2">
                                        <input id="setting-<?= e($setting['key']) ?>"
                                               type="checkbox"
                                               name="settings[<?= e($setting['key']) ?>]"
                                               value="1"
                                               class="h-4 w-4 accent-[color:var(--color-gold-500)]"
                                               <?= in_array((string) $setting['value'], ['1', 'true', 'on'], true) ? 'checked' : '' ?>>
                                        <span class="text-sm text-ink-300">Enabled</span>
                                    </label>

                                <?php elseif ($setting['type'] === 'json'): ?>
                                    <textarea id="setting-<?= e($setting['key']) ?>"
                                              name="settings[<?= e($setting['key']) ?>]"
                                              rows="4"
                                              class="input num text-xs <?= isset($errors[$setting['key']]) ? 'input-error' : '' ?>"
                                    ><?= e((string) $setting['value']) ?></textarea>

                                <?php else: ?>
                                    <input id="setting-<?= e($setting['key']) ?>"
                                           type="<?= $setting['is_secret'] ? 'password' : ($setting['type'] === 'int' || $setting['type'] === 'float' ? 'number' : 'text') ?>"
                                           <?= $setting['type'] === 'float' ? 'step="any"' : '' ?>
                                           name="settings[<?= e($setting['key']) ?>]"
                                           class="input num <?= isset($errors[$setting['key']]) ? 'input-error' : '' ?>"
                                           <?= $setting['is_secret'] ? 'autocomplete="off" placeholder="Unchanged"' : '' ?>
                                           value="<?= $setting['is_secret'] ? '' : e((string) $setting['value']) ?>">
                                    <?php if ($setting['is_secret']): ?>
                                        <p class="mt-1 text-xs text-ink-500">
                                            Stored value hidden. Leave blank to keep it.
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (isset($errors[$setting['key']])): ?>
                                    <p class="mt-1 text-xs text-bear-400"><?= e($errors[$setting['key']]) ?></p>
                                <?php endif; ?>

                                <p class="mt-1 text-xs text-ink-500">
                                    Last changed <?= e(substr($setting['updated_at'], 0, 16)) ?>
                                    by <?= e($setting['updated_by']) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="sticky bottom-0 -mx-4 border-t border-base-750 bg-base-950/90 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6">
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Save settings</button>
        </div>
    </form>
</div>
