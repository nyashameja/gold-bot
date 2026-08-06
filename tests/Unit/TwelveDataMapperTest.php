<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Integrations\MarketData\MarketDataException;
use GoldBot\Integrations\MarketData\TwelveData\TwelveDataMapper;
use PHPUnit\Framework\TestCase;

/**
 * Mapping is asserted against recorded fixtures rather than live traffic —
 * the only way to cover responses that are awkward to provoke on demand: a
 * partial bar, an absent volume field, an error envelope returned with HTTP
 * 200.
 *
 * These fixtures are shaped from Twelve Data's documented response format.
 * They must be re-recorded against the live API before Phase 3 is signed off
 * (docs/00, ADR-12 caveat 2 — the same discipline applies to this provider).
 */
final class TwelveDataMapperTest extends TestCase
{
    private TwelveDataMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TwelveDataMapper();
    }

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__) . '/Fixtures/TwelveData/' . $name . '.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function m15(): Timeframe
    {
        return new Timeframe(2, 'M15', 15, '15min');
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    /**
     * Twelve Data returns newest-first. Every indicator assumes oldest-first,
     * and a silently reversed series produces plausible but entirely wrong
     * values — the kind of bug that survives review.
     */
    public function test_bars_are_returned_oldest_first(): void
    {
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 11:00:00')
        );

        self::assertCount(3, $series);
        self::assertSame('2026-08-06 10:00:00', $series->first()?->openTime->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-06 10:30:00', $series->last()?->openTime->format('Y-m-d H:i:s'));
    }

    public function test_prices_are_mapped_as_exact_decimal_strings(): void
    {
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 11:00:00')
        );

        $bar = $series->last();

        self::assertNotNull($bar);
        self::assertSame('3312.45000', $bar->open);
        self::assertSame('3315.10000', $bar->high);
        self::assertSame('3311.80000', $bar->low);
        self::assertSame('3314.20000', $bar->close);
        self::assertIsString($bar->close, 'Prices stay strings end to end (ADR-11).');
    }

    /**
     * Closure is decided against our clock, never taken from the provider, so
     * the forming bar cannot slip into analysis (ADR-14).
     */
    public function test_the_forming_bar_is_not_marked_closed(): void
    {
        // 10:40 — the 10:30 bar is still forming; 10:15 and 10:00 have closed.
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 10:40:00')
        );

        $bars = $series->all();

        self::assertTrue($bars[0]->isClosed, '10:00 has closed.');
        self::assertTrue($bars[1]->isClosed, '10:15 has closed.');
        self::assertFalse($bars[2]->isClosed, '10:30 is still forming.');
        self::assertCount(2, $series->closedOnly());
    }

    public function test_the_settle_margin_delays_marking_a_bar_closed(): void
    {
        // 10:45:10 — the 10:30 bar has closed by the clock, but within the
        // 20-second settle margin the provider may not have published it yet.
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 10:45:10'),
            settleSeconds: 20
        );

        self::assertFalse($series->last()?->isClosed);

        $settled = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 10:45:25'),
            settleSeconds: 20
        );

        self::assertTrue($settled->last()?->isClosed);
    }

    public function test_close_time_is_the_last_instant_within_the_bar(): void
    {
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 11:00:00')
        );

        $bar = $series->first();

        self::assertSame('2026-08-06 10:00:00', $bar?->openTime->format('Y-m-d H:i:s'));
        self::assertSame(
            '2026-08-06 10:14:59',
            $bar?->closeTime->format('Y-m-d H:i:s'),
            'Close is inside the bar; 10:15:00 is the next bar\'s open.'
        );
    }

    /** Daily bars use a date-only datetime, which must still parse. */
    public function test_daily_bars_parse_a_date_only_datetime(): void
    {
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_daily'),
            new Timeframe(5, 'D1', 1440, '1day'),
            $this->utc('2026-08-06 11:00:00')
        );

        self::assertCount(2, $series);
        self::assertSame('2026-08-04 00:00:00', $series->first()?->openTime->format('Y-m-d H:i:s'));
        self::assertTrue($series->first()?->isClosed);
    }

    /**
     * Spot gold has no central exchange, so volume is frequently absent. Not
     * an error — just not a usable signal.
     */
    public function test_a_missing_volume_defaults_to_zero(): void
    {
        $series = $this->mapper->toCandleSeries(
            $this->fixture('time_series_15min'),
            $this->m15(),
            $this->utc('2026-08-06 11:00:00')
        );

        self::assertSame('0', $series->first()?->volume);
    }

    public function test_a_bar_missing_a_required_field_is_rejected(): void
    {
        $this->expectException(MarketDataException::class);
        $this->expectExceptionMessage('is missing `low`');

        $this->mapper->toCandleSeries(
            $this->fixture('time_series_malformed'),
            $this->m15(),
            $this->utc('2026-08-06 11:00:00')
        );
    }

    public function test_a_response_without_values_is_rejected(): void
    {
        $this->expectException(MarketDataException::class);
        $this->expectExceptionMessage('no `values` array');

        $this->mapper->toCandleSeries(['status' => 'ok'], $this->m15(), $this->utc('2026-08-06 11:00:00'));
    }

    public function test_a_quote_is_mapped_with_its_provider_timestamp(): void
    {
        $snapshot = $this->mapper->toPriceSnapshot(
            $this->fixture('quote'),
            $this->utc('2026-08-06 10:32:00')
        );

        self::assertSame('3314.20000', $snapshot->price);
        self::assertSame('3314.05000', $snapshot->bid);
        self::assertSame('3314.35000', $snapshot->ask);
        self::assertSame('0.30000', $snapshot->spread());
        self::assertNotNull($snapshot->providerTime);
        self::assertSame('2026-08-06 10:30:00', $snapshot->providerTime->format('Y-m-d H:i:s'));
    }

    /**
     * Data age is measured from the provider's timestamp, not our write, so a
     * stale quote is never presented as current (docs/01 §8).
     */
    public function test_quote_age_is_measured_from_the_provider_timestamp(): void
    {
        $snapshot = $this->mapper->toPriceSnapshot(
            $this->fixture('quote'),
            $this->utc('2026-08-06 10:32:00')
        );

        self::assertSame(300, $snapshot->ageSeconds($this->utc('2026-08-06 10:35:00')));
        self::assertTrue($snapshot->isStale($this->utc('2026-08-06 10:35:00'), 120));
        self::assertFalse($snapshot->isStale($this->utc('2026-08-06 10:31:00'), 120));
    }

    public function test_a_quote_without_a_price_is_rejected(): void
    {
        $this->expectException(MarketDataException::class);
        $this->expectExceptionMessage('no usable price');

        $this->mapper->toPriceSnapshot(['symbol' => 'XAU/USD'], $this->utc('2026-08-06 10:32:00'));
    }

    /**
     * Twelve Data signals errors inside a 200 body as often as by status code.
     * Mapping such a payload as data would store fabricated bars.
     */
    public function test_a_rate_limit_error_body_is_retryable(): void
    {
        try {
            $this->mapper->assertNotAnError($this->fixture('error_rate_limit'));
            self::fail('Expected a MarketDataException.');
        } catch (MarketDataException $e) {
            self::assertTrue($e->retryable, 'A rate limit clears on its own.');
            self::assertSame(429, $e->httpStatus);
        }
    }

    /** A bad key fails identically forever; retrying only burns quota. */
    public function test_an_invalid_key_error_is_not_retryable(): void
    {
        try {
            $this->mapper->assertNotAnError($this->fixture('error_bad_key'));
            self::fail('Expected a MarketDataException.');
        } catch (MarketDataException $e) {
            self::assertFalse($e->retryable);
            self::assertSame(401, $e->httpStatus);
        }
    }

    public function test_a_healthy_payload_passes_the_error_check(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapper->assertNotAnError($this->fixture('time_series_15min'));
    }
}
