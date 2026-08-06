<?php

declare(strict_types=1);

/**
 * Economic calendar import window.
 *
 * days_back overlaps deliberately: releases are revised after publication, and
 * re-polling recent history is how those revisions reach the archive. It costs
 * nothing — the upsert is idempotent.
 */
return [
    'days_back'    => 7,
    'days_forward' => 14,
];
