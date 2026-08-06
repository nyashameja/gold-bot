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
      Live market status. Polls /api/overview and shows the last price beside
      its age, so a stale quote is never presented as current (docs/01 §8).
      On a failed poll it degrades to "—" rather than disappearing: a widget
      that vanishes on error looks exactly like one that was never there.
    -->
    <div x-data="marketStatus" x-init="start"
         class="hidden items-center gap-2 rounded-full border border-base-700 bg-base-850
                px-3 py-1.5 sm:flex">
        <span class="dot" :class="dotClass" aria-hidden="true"></span>
        <span class="num text-xs text-ink-200" x-text="price">—</span>
        <span class="text-xs text-ink-500" x-text="age">loading</span>
    </div>
</header>
