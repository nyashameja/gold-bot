<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

/**
 * Requires the `signals.cancel` permission.
 *
 * The router addresses middleware by class name, so each permission gate
 * needs its own named class (one per file, per PSR-4).
 */
final class RequireSignalsCancel extends Authorize
{
    protected function permission(): string
    {
        return 'signals.cancel';
    }
}
