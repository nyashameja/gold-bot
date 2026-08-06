<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Session\TradingSession;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Domain\Structure\PriceLevel;
use GoldBot\Domain\Structure\StructureBreak;

/**
 * Builds StrategyContext instances for tests.
 *
 * That this is straightforward is the point of ADR-03: a strategy needs no
 * database, no network and no clock to be exercised, so its behaviour can be
 * pinned exactly rather than inferred from an integration run.
 */
final class StrategyContextFactory
{
    /** @var array<string,CandleSeries> */
    private array $series = [];

    /** @var array<string,array<string,?float>> */
    private array $indicators = [];

    /** @var array<string,TrendState> */
    private array $trends = [];

    /** @var list<PriceLevel> */
    private array $levels = [];

    /** @var list<StructureBreak> */
    private array $breaks = [];

    private ?TradingSession $session = null;

    private ?EconomicEvent $blockingEvent = null;

    private ?string $spread = null;

    private string $signalTimeframe = 'M15';

    public static function make(): self
    {
        return new self();
    }

    public function signalTimeframe(string $code): self
    {
        $this->signalTimeframe = strtoupper($code);

        return $this;
    }

    /**
     * A synthetic series with a controllable shape.
     *
     * `drift` moves price per bar; `pullbackBars` retraces at the end, so a
     * pullback rule can be driven to a known retracement.
     */
    public function withSeries(
        string $timeframe,
        int $bars = 60,
        float $start = 3300.0,
        float $drift = 1.0,
        int $pullbackBars = 0,
        float $pullbackDrift = -1.0
    ): self {
        $at = new DateTimeImmutable('2026-08-06 00:00:00', new DateTimeZone('UTC'));
        $candles = [];
        $price = $start;

        for ($i = 0; $i < $bars; $i++) {
            $step = $i >= ($bars - $pullbackBars) ? $pullbackDrift : $drift;

            $open = $price;
            $close = $price + $step;
            $high = max($open, $close) + 0.5;
            $low = min($open, $close) - 0.5;

            $openTime = $at->modify(sprintf('+%d minutes', $i * 15));

            $candles[] = new Candle(
                $openTime,
                $openTime->modify('+14 minutes 59 seconds'),
                number_format($open, 5, '.', ''),
                number_format($high, 5, '.', ''),
                number_format($low, 5, '.', ''),
                number_format($close, 5, '.', ''),
                '1000',
                true,
                $i + 1
            );

            $price = $close;
        }

        $this->series[strtoupper($timeframe)] = new CandleSeries($candles);

        return $this;
    }

    /** @param array<string,?float> $values */
    public function withIndicators(string $timeframe, array $values): self
    {
        $this->indicators[strtoupper($timeframe)] = $values;

        return $this;
    }

    public function withTrend(string $timeframe, TrendState $trend): self
    {
        $this->trends[strtoupper($timeframe)] = $trend;

        return $this;
    }

    /** @param list<PriceLevel> $levels */
    public function withLevels(array $levels): self
    {
        $this->levels = $levels;

        return $this;
    }

    /** @param list<StructureBreak> $breaks */
    public function withStructureBreaks(array $breaks): self
    {
        $this->breaks = $breaks;

        return $this;
    }

    public function withSession(string $code): self
    {
        $this->session = new TradingSession($code, $code, '00:00', '23:59', 'UTC');

        return $this;
    }

    public function withBlockingEvent(EconomicEvent $event): self
    {
        $this->blockingEvent = $event;

        return $this;
    }

    public function withSpread(string $spread): self
    {
        $this->spread = $spread;

        return $this;
    }

    public function build(): StrategyContext
    {
        $code = $this->signalTimeframe;

        if (!isset($this->series[$code])) {
            $this->withSeries($code);
        }

        return new StrategyContext(
            instrumentId:    1,
            timeframe:       new Timeframe(2, $code, 15, '15min'),
            at:              new DateTimeImmutable('2026-08-06 12:00:00', new DateTimeZone('UTC')),
            series:          $this->series,
            indicators:      $this->indicators,
            trends:          $this->trends,
            levels:          $this->levels,
            structureBreaks: $this->breaks,
            session:         $this->session,
            blockingEvent:   $this->blockingEvent,
            spread:          $this->spread
        );
    }
}
