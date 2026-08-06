<?php
/**
 * Centred layout for sign-in and error pages — no navigation, no session
 * chrome, nothing that assumes an authenticated user.
 *
 * @var string      $content
 * @var string|null $title
 */
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Sign in') ?> · Gold Bot</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
</head>
<body class="flex min-h-full items-center justify-center bg-base-950 px-4 py-10">

<!-- Subtle gold wash behind the card; decorative only. -->
<div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
    <div class="absolute left-1/2 top-0 h-[420px] w-[720px] -translate-x-1/2 rounded-full
                bg-gold-500/[0.055] blur-[120px]"></div>
</div>

<div class="relative w-full max-w-sm">
    <?= $content ?>
</div>
</body>
</html>
