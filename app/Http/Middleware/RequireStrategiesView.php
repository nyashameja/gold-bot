<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

/**
 * Requires the `strategies.view` permission.
 *
 * Separate from `strategies.edit`: reading the rubric a signal was scored
 * against is something every analyst needs, while changing it is not.
 */
final class RequireStrategiesView extends Authorize
{
    protected function permission(): string
    {
        return 'strategies.view';
    }
}
