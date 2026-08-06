<?php
/**
 * Users and roles.
 *
 * The forms below hide actions the current user cannot take, but that is
 * cosmetic — UserAdminService refuses them regardless, because a hidden
 * button is not an authorisation control (docs/01 §10).
 *
 * @var array<string,mixed> $board
 * @var bool $canManage
 * @var list<string> $timezones
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 * @var \GoldBot\Domain\Identity\User|null $authUser
 */
?>

<div x-data="userAdmin" class="space-y-6">

    <!-- Summary -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <?= $this->partial('partials.stat', [
            'label' => 'Users',
            'value' => (string) count($board['users']),
            'sub'   => sprintf(
                '%d active',
                count(array_filter($board['users'], static fn (array $u): bool => $u['is_active']))
            ),
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Administrators',
            'value' => (string) $board['admin_count'],
            // Named explicitly because the service refuses to remove the last
            // one, and that refusal is confusing without this number visible.
            'sub'   => $board['admin_count'] <= 1
                ? 'The last administrator cannot be demoted or deactivated'
                : 'Active accounts with full access',
            'tone'  => $board['admin_count'] <= 1 ? 'warn' : null,
        ]) ?>
        <?= $this->partial('partials.stat', [
            'label' => 'Roles defined',
            'value' => (string) count($board['roles']),
            'sub'   => 'Permissions are granted through roles only',
        ]) ?>
    </div>

    <!-- Create -->
    <?php if ($canManage): ?>
        <div class="card">
            <div class="border-b border-base-750 px-5 py-4">
                <h3 class="text-sm font-semibold text-ink-100">Add a user</h3>
            </div>
            <form method="post" action="/users" class="space-y-4 p-5">
                <?= $csrf->field() ?>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="new-name">Name</label>
                        <input id="new-name" type="text" name="name" required
                               class="input <?= isset($errors['name']) ? 'input-error' : '' ?>"
                               value="<?= e((string) ($old['name'] ?? '')) ?>">
                        <?php if (isset($errors['name'])): ?>
                            <p class="mt-1 text-xs text-bear-400"><?= e($errors['name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="label" for="new-email">Email</label>
                        <input id="new-email" type="email" name="email" required autocomplete="off"
                               class="input <?= isset($errors['email']) ? 'input-error' : '' ?>"
                               value="<?= e((string) ($old['email'] ?? '')) ?>">
                        <?php if (isset($errors['email'])): ?>
                            <p class="mt-1 text-xs text-bear-400"><?= e($errors['email']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="label" for="new-password">Password</label>
                        <input id="new-password" type="password" name="password" required minlength="12"
                               autocomplete="new-password"
                               class="input <?= isset($errors['password']) ? 'input-error' : '' ?>">
                        <p class="mt-1 text-xs text-ink-500">
                            At least 12 characters. Length beats punctuation — a long passphrase resists
                            guessing better than a short mangled word.
                        </p>
                        <?php if (isset($errors['password'])): ?>
                            <p class="mt-1 text-xs text-bear-400"><?= e($errors['password']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="label" for="new-timezone">Timezone</label>
                        <select id="new-timezone" name="timezone" class="input">
                            <?php foreach ($timezones as $zone): ?>
                                <option value="<?= e($zone) ?>" <?= $zone === 'UTC' ? 'selected' : '' ?>>
                                    <?= e($zone) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <fieldset>
                    <legend class="label">Roles</legend>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ($board['roles'] as $role): ?>
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-base-700
                                          bg-base-850 px-3 py-2 text-sm text-ink-300 transition hover:border-base-600">
                                <input type="checkbox" name="roles[]" value="<?= e($role['code']) ?>"
                                       class="h-4 w-4 accent-[color:var(--color-gold-500)]">
                                <span><?= e($role['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($errors['roles'])): ?>
                        <p class="mt-1 text-xs text-bear-400"><?= e($errors['roles']) ?></p>
                    <?php endif; ?>
                </fieldset>

                <button type="submit" class="btn btn-primary">Create user</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- List -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Accounts</h3>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[800px] text-sm">
                <thead>
                    <tr class="border-b border-base-750 text-left text-xs uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 font-medium">User</th>
                        <th class="px-5 py-3 font-medium">Roles</th>
                        <th class="px-5 py-3 font-medium">Last login</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <?php if ($canManage): ?>
                            <th class="px-5 py-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($board['users'] as $user): ?>
                        <?php $isSelf = $authUser !== null && $authUser->id === $user['id']; ?>
                        <tr class="border-b border-base-800 last:border-0">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                                                 bg-base-750 text-xs font-semibold text-gold-400">
                                        <?= e(strtoupper(substr($user['name'], 0, 2))) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="truncate text-ink-100">
                                            <?= e($user['name']) ?>
                                            <?php if ($isSelf): ?>
                                                <span class="text-xs text-ink-500">(you)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="truncate text-xs text-ink-500"><?= e($user['email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-3">
                                <?php if ($canManage): ?>
                                    <form method="post" action="/users/<?= e((string) $user['id']) ?>/roles"
                                          class="flex flex-wrap items-center gap-2">
                                        <?= $csrf->field() ?>
                                        <?php foreach ($board['roles'] as $role): ?>
                                            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-ink-400">
                                                <input type="checkbox" name="roles[]" value="<?= e($role['code']) ?>"
                                                       class="h-3.5 w-3.5 accent-[color:var(--color-gold-500)]"
                                                       <?= in_array($role['code'], $user['roles'], true) ? 'checked' : '' ?>>
                                                <?= e($role['code']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <button type="submit" class="btn btn-ghost !min-h-0 !px-2.5 !py-1 text-xs">
                                            Save
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($user['roles'] as $role): ?>
                                            <span class="badge badge-neutral"><?= e($role) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="num px-5 py-3 text-xs text-ink-500">
                                <?= $user['last_login_at'] === null ? 'Never' : e(substr($user['last_login_at'], 0, 16)) ?>
                            </td>

                            <td class="px-5 py-3">
                                <?= $this->partial('partials.status-pill', [
                                    'status' => $user['is_active'] ? 'OK' : 'UNKNOWN',
                                    'label'  => $user['is_active'] ? 'Active' : 'Deactivated',
                                ]) ?>
                            </td>

                            <?php if ($canManage): ?>
                                <td class="px-5 py-3 text-right">
                                    <?php if (!$isSelf): ?>
                                        <form method="post" action="/users/<?= e((string) $user['id']) ?>/active">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="active" value="<?= $user['is_active'] ? '0' : '1' ?>">
                                            <button type="submit"
                                                    class="btn btn-ghost !min-h-0 !px-3 !py-1.5 text-xs
                                                           <?= $user['is_active'] ? 'text-bear-400' : 'text-bull-400' ?>">
                                                <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
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
    </div>

    <!-- Roles reference -->
    <div class="card">
        <div class="border-b border-base-750 px-5 py-4">
            <h3 class="text-sm font-semibold text-ink-100">Roles</h3>
        </div>
        <ul class="divide-y divide-base-800">
            <?php foreach ($board['roles'] as $role): ?>
                <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <span class="badge badge-gold"><?= e($role['code']) ?></span>
                    <span class="flex-1 text-sm text-ink-300"><?= e($role['description'] ?? $role['name']) ?></span>
                    <span class="num text-xs text-ink-500">
                        <?= e((string) ($board['role_counts'][$role['code']] ?? 0)) ?> assigned
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
