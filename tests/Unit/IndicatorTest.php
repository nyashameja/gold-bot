<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Indicators\Atr;
use GoldBot\Domain\Indicators\BollingerBands;
use GoldBot\Domain\Indicators\Ema;
use GoldBot\Domain\Indicators\Macd;
use GoldBot\Domain\Indicators\Rsi;
use GoldBot\Domain\Indicators\Sma;
use GoldBot\Domain\Indicators\VolumeSma;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Indicator correctness.
 *
 * Two independent checks, because getting these subtly wrong is both easy and
 * expensive — ATR drives stop placement, so an error here costs money rather
 * than just looking odd:
 *
 * 1. Values that can be verified by hand on a tiny series.
 * 2. A 160-bar series cross-checked against a reference implementation
 *    written separately, in Python, from the textbook formulas
 *    (tests/Fixtures/indicator_reference.json).
 *
 * These are pure unit tests: no database, no network (ADR-03).
 */
final class IndicatorTest extends TestCase
{
    /** @var array{bars:list<array<string,float>>,expected:array<string,list<float|null>>} */
    private array $reference;

    protected function setUp(): void
    {
        $this->reference = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/Fixtures/indicator_reference.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @param list<float> $closes */
    private function seriesFromCloses(array $closes): CandleSeries
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $candles = [];

        foreach ($closes as $i => $close) {
            $open = $start->modify(sprintf('+%d minutes', $i * 15));
            $price = number_format($close, 5, '.', '');

            $candles[] = new Candle(
                $open,
                $open->modify('+14 minutes 59 seconds'),
                $price,
                $price,
                $price,
                $price,
                '0',
                true
            );
        }

        return new CandleSeries($candles);
    }

    private function referenceSeries(): CandleSeries
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $candles = [];

        foreach ($this->reference['bars'] as $i => $bar) {
            $open = $start->modify(sprintf('+%d minutes', $i * 15));

            $candles[] = new Candle(
                $open,
                $open->modify('+14 minutes 59 seconds'),
                number_format($bar['open'], 5, '.', ''),
                number_format($bar['high'], 5, '.', ''),
                number_format($bar['low'], 5, '.', ''),
                number_format($bar['close'], 5, '.', ''),
                number_format($bar['volume'], 5, '.', ''),
                true
            );
        }

        return new CandleSeries($candles);
    }

    /**
     * @param list<float|null> $expected
     * @param list<float|null> $actual
     */
    private function assertSeriesMatches(array $expected, array $actual, string $label): void
    {
        self::assertCount(count($expected), $actual, "{$label}: length mismatch.");

        foreach ($expected as $i => $value) {
            if ($value === null) {
                self::assertNull($actual[$i], "{$label}[{$i}] should be null during warm-up.");

                continue;
            }

            self::assertNotNull($actual[$i], "{$label}[{$i}] should have a value.");
            self::assertEqualsWithDelta($value, $actual[$i], 1e-9, "{$label}[{$i}] diverges.");
        }
    }

    // ── Hand-checkable cases ─────────────────────────────────────────────────

    public function test_sma_matches_a_hand_computed_average(): void
    {
        $series = $this->seriesFromCloses([1, 2, 3, 4, 5]);
        $result = (new Sma(3))->calculate($series);

        self::assertNull($result[0]);
        self::assertNull($result[1]);
        self::assertEqualsWithDelta(2.0, $result[2], 1e-9); // (1+2+3)/3
        self::assertEqualsWithDelta(3.0, $result[3], 1e-9); // (2+3+4)/3
        self::assertEqualsWithDelta(4.0, $result[4], 1e-9); // (3+4+5)/3
    }

    /**
     * The EMA is seeded with an SMA, as TradingView and MetaTrader do. Seeding
     * with the first close instead diverges for hundreds of bars, which would
     * put our EMA-200 out of step with the chart the user is reading.
     */
    public function test_ema_seeds_with_an_sma_then_smooths(): void
    {
        $series = $this->seriesFromCloses([1, 2, 3, 4, 5]);
        $result = (new Ema(3))->calculate($series);

        self::assertNull($result[1]);
        self::assertEqualsWithDelta(2.0, $result[2], 1e-9, 'Seed is the SMA of the first 3.');

        // k = 2/(3+1) = 0.5; e = (4 - 2)*0.5 + 2 = 3.0
        self::assertEqualsWithDelta(3.0, $result[3], 1e-9);
        // e = (5 - 3)*0.5 + 3 = 4.0
        self::assertEqualsWithDelta(4.0, $result[4], 1e-9);
    }

    /** An unbroken run of gains has no losses to divide by. */
    public function test_rsi_is_100_when_every_change_is_a_gain(): void
    {
        $result = (new Rsi(14))->calculate($this->seriesFromCloses(range(1, 30)));

        self::assertEqualsWithDelta(100.0, $result[14], 1e-9);
        self::assertEqualsWithDelta(100.0, $result[29], 1e-9);
    }

    public function test_rsi_is_zero_when_every_change_is_a_loss(): void
    {
        $result = (new Rsi(14))->calculate($this->seriesFromCloses(range(30, 1)));

        self::assertEqualsWithDelta(0.0, $result[14], 1e-9);
    }

    /** A perfectly flat series has neither gains nor losses. */
    public function test_rsi_is_50_on_an_unchanged_series(): void
    {
        $result = (new Rsi(14))->calculate($this->seriesFromCloses(array_fill(0, 30, 3300.0)));

        self::assertEqualsWithDelta(50.0, $result[14], 1e-9);
    }

    /**
     * True Range uses the previous close, which is what makes ATR account for
     * gaps — routine for gold across the weekend break.
     */
    public function test_true_range_accounts_for_a_gap(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $series = new CandleSeries([
            new Candle($start, $start->modify('+14 minutes'), '3300', '3305', '3295', '3300', '0', true),
            // Gaps up: opens at 3350, so the true range spans from the prior
            // close (3300) to this bar's high (3360), not merely 3345→3360.
            new Candle(
                $start->modify('+15 minutes'),
                $start->modify('+29 minutes'),
                '3350',
                '3360',
                '3345',
                '3355',
                '0',
                true
            ),
        ]);

        $ranges = (new Atr(14))->trueRanges($series);

        self::assertNull($ranges[0], 'The first bar has no previous close.');
        self::assertEqualsWithDelta(60.0, $ranges[1], 1e-9, 'high(3360) - previous close(3300).');
    }

    public function test_indicators_return_all_nulls_when_the_series_is_too_short(): void
    {
        $series = $this->seriesFromCloses([1, 2, 3]);

        foreach ([new Sma(20), new Ema(50), new Rsi(14), new Atr(14)] as $indicator) {
            $result = $indicator->calculate($series);

            self::assertCount(3, $result);
            self::assertSame(
                [null, null, null],
                $result,
                $indicator->name() . ' must not produce a partial value.'
            );
        }
    }

    public function test_an_empty_series_yields_an_empty_result(): void
    {
        self::assertSame([], (new Ema(50))->calculate(new CandleSeries([])));
    }

    public function test_invalid_periods_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ema(0);
    }

    public function test_macd_rejects_a_fast_period_that_is_not_faster(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Macd(26, 12);
    }

    // ── Cross-checks against the independent reference ───────────────────────

    public function test_sma_matches_the_reference(): void
    {
        $this->assertSeriesMatches(
            $this->reference['expected']['sma_20'],
            (new Sma(20))->calculate($this->referenceSeries()),
            'sma_20'
        );
    }

    public function test_ema_matches_the_reference(): void
    {
        $this->assertSeriesMatches(
            $this->reference['expected']['ema_50'],
            (new Ema(50))->calculate($this->referenceSeries()),
            'ema_50'
        );
    }

    /** 160 bars cannot warm up a 200-period EMA — every value must be null. */
    public function test_ema_200_stays_null_on_a_short_series(): void
    {
        $result = (new Ema(200))->calculate($this->referenceSeries());

        self::assertCount(160, $result);
        self::assertSame([], array_filter($result, static fn (?float $v): bool => $v !== null));
    }

    public function test_rsi_matches_the_reference(): void
    {
        $this->assertSeriesMatches(
            $this->reference['expected']['rsi_14'],
            (new Rsi(14))->calculate($this->referenceSeries()),
            'rsi_14'
        );
    }

    public function test_atr_matches_the_reference(): void
    {
        $this->assertSeriesMatches(
            $this->reference['expected']['atr_14'],
            (new Atr(14))->calculate($this->referenceSeries()),
            'atr_14'
        );
    }

    public function test_macd_matches_the_reference(): void
    {
        $result = (new Macd())->calculateAll($this->referenceSeries());

        $this->assertSeriesMatches($this->reference['expected']['macd'], $result['macd'], 'macd');
        $this->assertSeriesMatches($this->reference['expected']['macd_signal'], $result['signal'], 'macd_signal');
        $this->assertSeriesMatches($this->reference['expected']['macd_histogram'], $result['histogram'], 'macd_histogram');
    }

    public function test_bollinger_bands_match_the_reference(): void
    {
        $result = (new BollingerBands())->calculateAll($this->referenceSeries());

        $this->assertSeriesMatches($this->reference['expected']['bb_upper'], $result['upper'], 'bb_upper');
        $this->assertSeriesMatches($this->reference['expected']['bb_middle'], $result['middle'], 'bb_middle');
        $this->assertSeriesMatches($this->reference['expected']['bb_lower'], $result['lower'], 'bb_lower');
    }

    public function test_volume_sma_matches_the_reference(): void
    {
        $this->assertSeriesMatches(
            $this->reference['expected']['volume_sma_20'],
            (new VolumeSma(20))->calculate($this->referenceSeries()),
            'volume_sma_20'
        );
    }

    public function test_the_bands_bracket_the_middle(): void
    {
        $result = (new BollingerBands())->calculateAll($this->referenceSeries());

        for ($i = 19; $i < 160; $i++) {
            self::assertGreaterThan((float) $result['middle'][$i], (float) $result['upper'][$i]);
            self::assertLessThan((float) $result['middle'][$i], (float) $result['lower'][$i]);
        }
    }

    public function test_rsi_stays_within_bounds_across_the_reference_series(): void
    {
        foreach ((new Rsi(14))->calculate($this->referenceSeries()) as $value) {
            if ($value === null) {
                continue;
            }

            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThanOrEqual(100.0, $value);
        }
    }

    public function test_atr_is_never_negative(): void
    {
        foreach ((new Atr(14))->calculate($this->referenceSeries()) as $value) {
            if ($value !== null) {
                self::assertGreaterThanOrEqual(0.0, $value);
            }
        }
    }
}
