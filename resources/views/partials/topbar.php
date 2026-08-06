<?php
/**
 * Sticky page header.
 *
 * @var string $title
 */
?>
<header class="glass sticky top-0 z-20 flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">

    <!-- Drawer toggle, below lg only -->
    <button type="button"
            @click="open"
            class="-ml-2 flex h-11 w-11 items-center justify-center rounded-lg text-ink-400
                   transition hover:bg-base-800 hover:text-ink-100 lg:hidden"
            aria-label="Open navigation">
        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <path d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>

    <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-ink-100 sm:text-lg">
        <?= e($title ?? 'Dashboard') ?>
    </h1>

    <!--
      Market status. Static in Phase 2 — it is wired to price_snapshots in
      Phase 8, and will carry a data-age indicator so a stale price is never
      shown as current (docs/01 §8).
    -->
    <div class="hidden items-center gap-2 rounded-full border border-base-700 bg-base-850
                px-3 py-1.5 sm:flex">
        <span class="dot bg-ink-500" aria-hidden="true"></span>
        <span class="text-xs text-ink-400">Awaiting market data</span>
    </div>
</header>
