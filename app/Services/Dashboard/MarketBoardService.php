<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Session\SessionResolver;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketStructureRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Services\MarketData\StructureService;
use RuntimeException;

/**
 * Everything the Live Market page reads.
 *
 * Reads MySQL and nothing else — no provider call happens in a web request
 * (docs/01 §8). The consequence worth stating plainly: this page can be fully
 * rendered with the network unplugged, and its numbers are exactly as fresh as
 * the last cron run. That is why every block returned here carries a DataAge.
 */
final class MarketBoardService
{
    /** How many candles the chart and its overlays work with. */
    private const CHART_BARS = 250;

    public function __construct(
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly CandleRepositoryInterface $candles,
        private readonly IndicatorRepositoryInterface $indicators,
        private readonly MarketStructureRepositoryInterface $structure,
        private readonly SignalRepositoryInterface $signals,
        private readonly StructureService $structureService,
        private readonly SessionResolver $sessions,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * The default instrument. XAU/USD today; the lookup goes through the
     * reference table so adding a second instrument is a row, not a rewrite
     * (docs/01 §3).
     *
     * @return array<string,mixed>
     */
    public function defaultInstrument(): array
    {
        $instruments = $this->reference->activeInstruments();

        if ($instruments === []) {
            throw new RuntimeException('No active instrument is configured. Run the reference seed.');
        }

        return $instruments[0];
    }

    /** @return array<string,mixed> */
    public function instrumentBySymbol(?string $symbol): array
    {
        if ($symbol === null) {
            return $this->defaultInstrument();
        }

        return $this->reference->instrumentBySymbol($symbol) ?? $this->defaultInstrument();
    }

    public function resolveTimeframe(?string $code): Timeframe
    {
        $active = $this->reference->activeTimeframes();

        if ($active === []) {
            throw new RuntimeException('No active timeframe is configured. Run the reference seed.');
        }

        if ($code !== null) {
            foreach ($active as $timeframe) {
                if (strcasecmp($timeframe->code, $code) === 0) {
                    return $timeframe;
                }
            }
        }

        // Default to H1: long enough to be meaningful, short enough to move.
        foreach ($active as $timeframe) {
            if ($timeframe->code === 'H1') {
                return $timeframe;
            }
        }

        return $active[0];
    }

    /**
     * The quote tile: last price, day range, spread, and how old it is.
     *
     * @return array<string,mixed>
     */
    public function quote(int $instrumentId): array
    {
        $now = $this->clock->now();
        $snapshot = $this->snapshots->latest($instrumentId);

        if ($snapshot === null) {
            // The SAME keys as the populated branch, nulled. A narrower shape
            // here is a trap: every consumer then has to guard each key
            // individually, and the one that forgets only fails on a database
            // with no quote yet — which is exactly the state a fresh install
            // is in, and the last one anybody tests.
            return [
                'available'       => false,
                'price'           => null,
                'bid'             => null,
                'ask'             => null,
                'spread'          => null,
                'day_high'        => null,
                'day_low'         => null,
                'change_absolute' => null,
                'change_percent'  => null,
                'direction'       => 'FLAT',
                'age'             => DataAge::since(null, $now, 60)->toArray(),
            ];
        }

        $reference = $snapshot->providerTime ?? $snapshot->capturedAt;

        return [
            'available'       => true,
            'price'           => $snapshot->price,
            'bid'             => $snapshot->bid,
            'ask'             => $snapshot->ask,
            'spread'          => $snapshot->spread(),
            'day_high'        => $snapshot->dayHigh,
            'day_low'         => $snapshot->dayLow,
            'change_absolute' => $snapshot->changeAbsolute,
            'change_percent'  => $snapshot->changePercent,
            'direction'       => $this->changeDirection($snapshot->changeAbsolute),
            // A snapshot cron runs every minute, so a minute is the cadence
            // this age is judged against.
            'age'             => DataAge::since($reference, $now, 60)->toArray(),
        ];
    }

    /**
     * Candles plus indicator values, aligned by open time, for the chart.
     *
     * Two queries, joined in PHP by open time rather than in SQL, because the
     * indicator row for the newest candle may not exist yet — the indicator
     * cron runs after the candle cron, and an inner join would silently drop
     * the most recent bar.
     *
     * @return array<string,mixed>
     */
    public function chart(int $instrumentId, Timeframe $timeframe, int $bars = self::CHART_BARS): array
    {
        $now = $this->clock->now();
        $series = $this->candles->latest($instrumentId, $timeframe->id, $bars, closedOnly: false);
        $indicatorRows = $this->indicators->window($instrumentId, $timeframe->id, $bars);

        $byOpenTime = [];

        foreach ($indicatorRows as $row) {
            $byOpenTime[(string) $row['open_time']] = $row;
        }

        $candles = [];

        foreach ($series as $candle) {
            $key = $candle->openTime->format('Y-m-d H:i:s');
            $indicator = $byOpenTime[$key] ?? null;

            $candles[] = [
                't'         => $candle->openTime->getTimestamp(),
                'o'         => (float) $candle->open,
                'h'         => (float) $candle->high,
                'l'         => (float) $candle->low,
                'c'         => (float) $candle->close,
                'v'         => (float) $candle->volume,
                'closed'    => $candle->isClosed,
                'ema50'     => $this->floatOrNull($indicator['ema_50'] ?? null),
                'ema200'    => $this->floatOrNull($indicator['ema_200'] ?? null),
                'rsi'       => $this->floatOrNull($indicator['rsi_14'] ?? null),
                'bb_upper'  => $this->floatOrNull($indicator['bb_upper'] ?? null),
                'bb_lower'  => $this->floatOrNull($indicator['bb_lower'] ?? null),
            ];
        }

        $newest = $series->last();

        return [
            'timeframe' => $timeframe->code,
            'candles'   => $candles,
            // Judged against the timeframe's own duration: an H4 chart whose
            // newest bar is 30 minutes old is perfectly current.
            'age'       => DataAge::since($newest?->openTime, $now, $timeframe->seconds())->toArray(),
        ];
    }

    /**
     * Chart overlays: structure points, levels and the open signals whose
     * entry, stop and targets belong on the price axis.
     *
     * @return array<string,mixed>
     */
    public function overlays(int $instrumentId, Timeframe $timeframe): array
    {
        $points = array_map(
            static fn (array $p): array => [
                'type'      => (string) $p['type'],
                'price'     => (float) $p['price'],
                'direction' => $p['direction'] === null ? null : (string) $p['direction'],
                'strength'  => (int) $p['strength'],
                'at'        => (string) $p['occurred_at'],
            ],
            $this->structure->points($instrumentId, $timeframe->id)
        );

        $levels = array_map(
            static fn (array $l): array => [
                'type'     => (string) $l['type'],
                'from'     => (float) $l['price_from'],
                'to'       => (float) $l['price_to'],
                'strength' => (int) $l['strength'],
                'touches'  => (int) $l['touch_count'],
                'at'       => (string) $l['formed_at'],
            ],
            $this->structure->levels($instrumentId, $timeframe->id)
        );

        return [
            'points'  => $points,
            'levels'  => $levels,
            'signals' => $this->openSignalOverlays($instrumentId, $timeframe->id),
        ];
    }

    /**
     * A row per timeframe: trend, the latest indicator readings, and how old
     * they are. This is the "market at a glance" strip.
     *
     * One indicator query per timeframe — five queries for five timeframes,
     * not one per candle. There is no per-row query anywhere below.
     *
     * @return list<array<string,mixed>>
     */
    public function timeframeSummary(int $instrumentId): array
    {
        $now = $this->clock->now();
        $rows = [];

        foreach ($this->reference->activeTimeframes() as $timeframe) {
            $indicator = $this->indicators->latestFor($instrumentId, $timeframe->id);
            $lastBreak = $this->structure->lastBreak($instrumentId, $timeframe->id);
            $candle = $this->candles->mostRecent($instrumentId, $timeframe->id, closedOnly: true);

            $rows[] = [
                'code'       => $timeframe->code,
                'minutes'    => $timeframe->minutes,
                'trend'      => $this->structureService->currentTrend($instrumentId, $timeframe)->value,
                'close'      => $candle === null ? null : (float) $candle->close,
                'ema50'      => $this->floatOrNull($indicator['ema_50'] ?? null),
                'ema200'     => $this->floatOrNull($indicator['ema_200'] ?? null),
                'rsi'        => $this->floatOrNull($indicator['rsi_14'] ?? null),
                'atr'        => $this->floatOrNull($indicator['atr_14'] ?? null),
                'macd_hist'  => $this->floatOrNull($indicator['macd_histogram'] ?? null),
                'last_break' => $lastBreak === null ? null : [
                    'type'      => (string) $lastBreak['type'],
                    'direction' => $lastBreak['direction'] === null ? null : (string) $lastBreak['direction'],
                    'at'        => (string) $lastBreak['occurred_at'],
                ],
                'age'        => DataAge::since($candle?->openTime, $now, $timeframe->seconds())->toArray(),
            ];
        }

        return $rows;
    }

    /**
     * Which sessions are open right now.
     *
     * Computed from the session definitions rather than stored, because the
     * answer changes every minute and a cached one would be wrong more often
     * than right.
     *
     * @return list<array<string,mixed>>
     */
    public function sessions(): array
    {
        $now = $this->clock->now();
        $open = $this->sessions->activeCodesAt($now);

        return array_map(
            static fn (\GoldBot\Domain\Session\TradingSession $session): array => [
                'code'   => $session->code,
                'name'   => $session->name,
                'open'   => $session->openTime,
                'close'  => $session->closeTime,
                'zone'   => $session->timezone()->getName(),
                'active' => in_array($session->code, $open, true),
            ],
            $this->sessions->all()
        );
    }

    /**
     * Open signals reduced to the price lines the chart needs.
     *
     * @return list<array<string,mixed>>
     */
    private function openSignalOverlays(int $instrumentId, int $timeframeId): array
    {
        $open = array_values(array_filter(
            $this->signals->open($instrumentId),
            static fn (array $s): bool => (int) $s['timeframe_id'] === $timeframeId
        ));

        // One query for every signal's targets, not one per signal.
        $targets = $this->signals->targetsFor(array_map(
            static fn (array $s): int => (int) $s['id'],
            $open
        ));

        return array_map(
            static fn (array $signal): array => [
                'uuid'      => (string) $signal['uuid'],
                'direction' => (string) $signal['direction'],
                'state'     => (string) $signal['state'],
                'entry'     => (float) $signal['entry_price'],
                'stop'      => (float) $signal['stop_loss'],
                'targets'   => array_map(
                    static fn (array $t): array => [
                        'level' => (int) $t['level'],
                        'price' => (float) $t['price'],
                        'hit'   => $t['hit_at'] !== null,
                    ],
                    $targets[(int) $signal['id']] ?? []
                ),
            ],
            $open
        );
    }

    private function changeDirection(?string $change): string
    {
        if ($change === null) {
            return 'FLAT';
        }

        return match (true) {
            (float) $change > 0 => 'UP',
            (float) $change < 0 => 'DOWN',
            default             => 'FLAT',
        };
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
