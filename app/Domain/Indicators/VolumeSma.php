<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;

/**
 * Simple moving average of volume.
 *
 * Spot gold has no central exchange, so volume is frequently zero or absent
 * (see TwelveDataMapper). This indicator is computed regardless — it is
 * meaningful on futures and on any instrument added in V2 — but a strategy
 * must not treat a flat zero as a signal.
 */
final class VolumeSma implements IndicatorInterface
{
    public function __construct(private readonly int $period = 20)
    {
    }

    public function calculate(CandleSeries $series): array
    {
        $volumes = array_map(
            static fn (Candle $c): float => (float) $c->volume,
            $series->all()
        );

        return Sma::overValues($volumes, $this->period);
    }

    public function warmUpBars(): int
    {
        return $this->period;
    }

    public function name(): string
    {
        return 'volume_sma_' . $this->period;
    }
}
