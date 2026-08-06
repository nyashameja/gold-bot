<?php
/**
 * Dashboard shell.
 *
 * Mobile-first: the sidebar is an off-canvas drawer below `lg` and a fixed
 * rail from `lg` up. Alpine holds only the drawer's open state — page data
 * comes from the server (docs/01 §8).
 *
 * @var string      $content
 * @var string|null $title
 * @var string      $currentPath
 * @var array       $flash
 */
$title = $title ?? 'Dashboard';
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title><?= e($title) ?> · Gold Bot</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
</head>
<body class="h-full bg-base-950 text-ink-300 antialiased">
<div x-data="shell" @keydown.window="onKeydown" class="min-h-full">

    <!-- Off-canvas backdrop (below lg only) -->
    <div x-show="navOpen"
         x-transition.opacity
         @click="close"
         class="fixed inset-0 z-30 bg-black/60 lg:hidden"
         x-cloak
         aria-hidden="true"></div>

    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 z-40 w-72 border-r border-base-750 bg-base-900
                  transition-transform duration-200 lg:translate-x-0"
           :class="panelClass"
           x-cloak>
        <?= $this->partial('partials.sidebar', ['currentPath' => $currentPath ?? '/']) ?>
    </aside>

    <!-- Content column -->
    <div class="lg:pl-72">
        <?= $this->partial('partials.topbar', ['title' => $title]) ?>

        <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <?= $this->partial('partials.flash', ['flash' => $flash ?? []]) ?>
            <?= $content ?>
        </main>
    </div>
</div>

<!--
  Chart.js loads only on the three pages that draw charts. It is ~200KB, and
  shipping it everywhere would be paid on every mobile page load by readers who
  never see a canvas. It must precede app.js, which sets Chart defaults.
-->
<?php if (!empty($charts)): ?>
    <script src="/assets/js/chart.min.js" defer></script>
<?php endif; ?>

<!-- app.js registers the components before Alpine boots; all are deferred,
     so they execute in document order. -->
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/alpine-csp.min.js" defer></script>
</body>
</html>
