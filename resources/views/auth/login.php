<?php
/**
 * Sign-in form.
 *
 * @var \Paragon\Core\Support\Csrf $csrf
 * @var array<string,mixed>            $flash
 * @var array<string,mixed>            $old
 */
$title = 'Sign in';
?>
<div class="mb-8 text-center">
    <div class="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-gold-500 text-base-950">
        <svg viewBox="0 0 24 24" class="h-7 w-7" fill="currentColor" aria-hidden="true">
            <path d="M12 2 4 7v10l8 5 8-5V7z" opacity=".35"/>
            <path d="m12 6-4 2.5v5l4 2.5 4-2.5v-5z"/>
        </svg>
    </div>
    <h1 class="text-xl font-semibold text-ink-100">Gold Bot</h1>
    <p class="mt-1 text-sm text-ink-500">XAU/USD market intelligence</p>
</div>

<div class="card p-6 sm:p-7">
    <?php if (!empty($flash['error'])): ?>
        <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-bear-500/30 bg-bear-500/10 px-3.5 py-3"
             role="alert">
            <svg viewBox="0 0 24 24" class="mt-0.5 h-4 w-4 shrink-0 text-bear-400" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/>
            </svg>
            <p class="text-sm text-bear-400"><?= e($flash['error']) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($flash['success'])): ?>
        <div class="mb-5 rounded-lg border border-bull-500/30 bg-bull-500/10 px-3.5 py-3" role="status">
            <p class="text-sm text-bull-400"><?= e($flash['success']) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="/login" novalidate>
        <?= $csrf->field() ?>

        <div class="mb-4">
            <label for="email" class="label">Email address</label>
            <input type="email"
                   id="email"
                   name="email"
                   value="<?= e($old['email'] ?? '') ?>"
                   class="input"
                   autocomplete="username"
                   inputmode="email"
                   required
                   autofocus>
        </div>

        <div class="mb-6">
            <label for="password" class="label">Password</label>
            <input type="password"
                   id="password"
                   name="password"
                   class="input"
                   autocomplete="current-password"
                   required>
        </div>

        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>
</div>

<p class="mt-6 text-center text-xs text-ink-500">
    Internal system · The Paragon Design
</p>
