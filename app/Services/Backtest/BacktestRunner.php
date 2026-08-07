<?php

declare(strict_types=1);

namespace GoldBot\Services\Backtest;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Backtest\SimulatedTrade;
use GoldBot\Domain\Backtest\TradeOutcomeType;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Performance\PerformanceCalculator;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Domain\Strategy\StrategyInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Services\Signals\StrategyContextBuilder;
use Paragon\Core\Container;
use Paragon\Core\Logging\LoggerInterface;
use RuntimeException;

/**
 * Replays stored candles through the live strategy objects (ADR-04).
 *
 * The whole harness is cheap only because of ADR-03: strategies are pure and
 * free of I/O, so the same object that runs live can be handed a historical
 * context and will behave identically. Nothing here reimplements a strategy —
 * if it did, a backtest would measure the reimplementation.
 *
 * ── The property that matters ────────────────────────────────────────────────
 *
 * NO LOOKAHEAD. At each bar the context is built with `asOf` set to that bar's
 * close, so the strategy can see that bar and nothing after it — including on
 * the higher timeframes, which is where lookahead actually creeps in. An H1
 * bar closing at 10:00 must not be visible when evaluating the M15 bar that
 * closed at 09:15, and a backtester that gets this wrong reports a strategy
 * that cannot exist.
 *
 * ── The unavoidable ambiguity ────────────────────────────────────────────────
 *
 * When one candle's range covers both the stop and a target, the order inside
 * that candle is unknowable. This assumes the STOP came first — the same
 * pessimistic rule SignalLifecycleService applies live, for the same reason: a
 * simulation that resolves ambiguity in its own favour reports a win rate the
 * live account will never reproduce.
 */
final class BacktestRunner
{
    /** Bars of history the context needs behind the first evaluated bar. */
    private const WARM_UP_BARS = 250;

    public function __construct(
        private readonly Container $container,
        private readonly StrategyRepositoryInterface $strategies,
        private readonly CandleRepositoryInterface $candles,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly EconomicEventRepositoryInterface $events,
        private readonly StrategyContextBuilder $contexts,
        private readonly PerformanceCalculator $calculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $options
     *        strategy      string   Strategy code. Required.
     *        from, to      DateTimeImmutable
     *        min_score     float    Overrides the config's threshold.
     *        news_filter   bool     Suppress signals inside a blackout window.
     *        expiry_bars   int      Bars an unfilled entry stays live.
     * @return array<string,mixed>
     */
    public function run(array $options): array
    {
        $started = microtime(true);

        $code = (string) ($options['strategy'] ?? '');
        $strategy = $this->strategies->findByCode($code);

        if ($strategy === null) {
            throw new RuntimeException("No such strategy: {$code}");
        }

        $strategyId = (int) $strategy['id'];
        $config = $this->configFor($strategyId, $options);
        $instance = $this->instantiate((string) $strategy['class_name']);

        $instrument = $this->reference->activeInstruments()[0] ?? null;

        if ($instrument === null) {
            throw new RuntimeException('No active instrument is configured.');
        }

        $instrumentId = (int) $instrument['id'];
        $timeframe = $this->reference->timeframeByCode($config->string('signal_timeframe', 'M15'));

        if ($timeframe === null) {
            throw new RuntimeException('The strategy names a timeframe that does not exist.');
        }

        [$from, $to] = $this->window($instrumentId, $timeframe, $options);
        $newsFilter = (bool) ($options['news_filter'] ?? false);

        $this->guardNewsArchive($newsFilter, $from);

        $minScore = isset($options['min_score'])
            ? (float) $options['min_score']
            : $config->float('min_score', 70.0);

        // Loaded once. A threshold sweep re-runs the simulation many times, and
        // re-reading several hundred bars per pass is the whole cost.
        $bars = $this->candles->between($instrumentId, $timeframe->id, $from, $to, closedOnly: true);

        if ($bars->isEmpty()) {
            throw new RuntimeException('No closed candles in that period. Backfill first.');
        }

        $required = $instance->requiredTimeframes($config);
        $expiryBars = max(1, (int) ($options['expiry_bars'] ?? 16));

        /** @var list<SimulatedTrade> $trades */
        $trades = [];
        $evaluated = 0;

        // Every bar's raw result, before any filtering. This is what makes the
        // harness verifiable: the live engine records the same thing in
        // strategy_runs, and the two must agree bar for bar. If they do not,
        // either a strategy is not pure (ADR-03) or the replay is seeing
        // different data — and both are silent failures otherwise.
        $evaluations = [];

        foreach ($bars as $bar) {
            // Every open trade is advanced against THIS bar before the bar is
            // evaluated for a new signal. Doing it the other way round would
            // let a signal generated on this bar be filled by the same bar's
            // range — an entry the live system could not have taken, because
            // the bar had not closed when the signal was produced.
            $this->advance($trades, $bar, $expiryBars, $timeframe->minutes);

            $context = $this->contexts->build(
                $instrumentId,
                $timeframe,
                $required,
                $bar->closeTime,
                historical: true
            );

            if ($context === null) {
                continue;
            }

            $evaluated++;

            if ($newsFilter && $context->blockingEvent !== null) {
                continue;
            }

            $result = $instance->evaluate($context, $config);

            $evaluations[] = [
                'candle_open_time' => $bar->openTime->format('Y-m-d H:i:s'),
                'score'            => round($result->score, 2),
                'direction'        => $result->direction?->value,
                'qualified'        => $result->qualified,
            ];

            if (!$this->qualifies($result, $minScore)) {
                continue;
            }

            // One open trade at a time. Position sizing and correlated
            // exposure are out of scope; without this a strategy that fires on
            // consecutive bars would report the same move a dozen times and
            // look far better than it is.
            if ($this->hasOpenTrade($trades)) {
                continue;
            }

            $trades[] = $this->openTrade($result, $bar, $context->session?->code, $expiryBars, $timeframe->minutes);
        }

        $outcomes = [];

        foreach ($trades as $trade) {
            $outcome = $trade->toOutcome();

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        $metrics = $this->calculator->calculate($outcomes);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->logger->info('Backtest complete', [
            'event'     => 'backtest.complete',
            'strategy'  => $code,
            'from'      => $from->format('Y-m-d'),
            'to'        => $to->format('Y-m-d'),
            'min_score' => $minScore,
            'trades'    => count($trades),
            'net_r'     => $metrics->totalR,
        ]);

        return [
            'strategy_id'   => $strategyId,
            'strategy_code' => $code,
            'config'        => $config,
            'instrument_id' => $instrumentId,
            'timeframe'     => $timeframe,
            'from'          => $from,
            'to'            => $to,
            'min_score'     => $minScore,
            'news_filter'   => $newsFilter,
            'bars'          => count($bars),
            'evaluated'     => $evaluated,
            'evaluations'   => $evaluations,
            'trades'        => $trades,
            'metrics'       => $metrics,
            'equity'        => $this->calculator->equityCurve($outcomes),
            'still_open'    => count(array_filter($trades, static fn (SimulatedTrade $t): bool => $t->isOpen())),
            'duration_ms'   => $durationMs,
        ];
    }

    /**
     * ADR-15: refuse a news-filtered run over a period the archive does not
     * cover.
     *
     * The upstream calendar is a rolling window, so events before the first
     * import do not exist locally and never will. Running the filter over that
     * period would silently apply no filter at all, and the result would look
     * like evidence that the news filter costs nothing — a conclusion drawn
     * from its absence. Refusing is the only honest option.
     */
    private function guardNewsArchive(bool $newsFilter, DateTimeImmutable $from): void
    {
        if (!$newsFilter) {
            return;
        }

        $archiveStart = $this->events->earliestScheduledAt();

        if ($archiveStart === null) {
            throw new RuntimeException(
                'The news filter was requested but no economic events are archived. '
                . 'A run over an unarchived period would be silently unfiltered (ADR-15).'
            );
        }

        if ($from < $archiveStart) {
            throw new RuntimeException(sprintf(
                'The news filter was requested from %s, but the archive only begins %s. '
                . 'The upstream feed is a rolling window, so that history does not exist '
                . 'locally and never will (ADR-15). Either start the run at %s or drop the '
                . 'news filter and read the result knowing it is unfiltered.',
                $from->format('Y-m-d'),
                $archiveStart->format('Y-m-d'),
                $archiveStart->format('Y-m-d')
            ));
        }
    }

    /**
     * Advance every open trade against one bar.
     *
     * @param list<SimulatedTrade> $trades
     */
    private function advance(array $trades, Candle $bar, int $expiryBars, int $timeframeMinutes): void
    {
        $high = (float) $bar->high;
        $low = (float) $bar->low;

        foreach ($trades as $trade) {
            if (!$trade->isOpen()) {
                continue;
            }

            $trade->barsHeld++;

            // Entry fills when the bar's range covers the entry price.
            if ($trade->outcome === TradeOutcomeType::Pending) {
                if ($high >= $trade->entryPrice && $low <= $trade->entryPrice) {
                    $trade->activatedAt = $bar->closeTime;
                    $trade->outcome = TradeOutcomeType::Open;
                } elseif ($trade->expiresAt !== null && $bar->closeTime >= $trade->expiresAt) {
                    $trade->outcome = TradeOutcomeType::Expired;
                    $trade->closedAt = $bar->closeTime;
                    continue;
                } else {
                    continue;
                }
            }

            // Stop first — see the class comment on intra-candle ambiguity.
            $stopHit = $trade->direction->isBuy()
                ? $low <= $trade->stopLoss
                : $high >= $trade->stopLoss;

            if ($stopHit) {
                $trade->close($bar->closeTime, $trade->stopLoss);
                continue;
            }

            foreach ($trade->targets as $target) {
                if ($target['level'] <= $trade->targetsHit) {
                    continue;
                }

                $reached = $trade->direction->isBuy()
                    ? $high >= $target['price']
                    : $low <= $target['price'];

                if (!$reached) {
                    break;
                }

                $trade->targetsHit = $target['level'];

                // The final target closes the trade.
                if ($target['level'] === count($trade->targets)) {
                    $trade->close($bar->closeTime, $target['price']);
                    break;
                }
            }
        }
    }

    /** @param list<SimulatedTrade> $trades */
    private function hasOpenTrade(array $trades): bool
    {
        foreach ($trades as $trade) {
            if ($trade->isOpen()) {
                return true;
            }
        }

        return false;
    }

    private function openTrade(
        SignalResult $result,
        Candle $bar,
        ?string $sessionCode,
        int $expiryBars,
        int $timeframeMinutes
    ): SimulatedTrade {
        $targets = array_map(
            static fn ($target): array => [
                'level'     => $target->level,
                'price'     => $target->price,
                'rMultiple' => $target->rMultiple,
            ],
            $result->targets
        );

        return new SimulatedTrade(
            signalledAt: $bar->closeTime,
            direction: $result->direction ?? Direction::Buy,
            score: $result->score,
            entryPrice: (float) $result->entryPrice,
            stopLoss: (float) $result->stopLoss,
            riskDistance: (float) $result->riskDistance(),
            riskReward: $result->riskReward(),
            targets: $targets,
            sessionCode: $sessionCode,
            expiresAt: $bar->closeTime->modify(sprintf('+%d minutes', $expiryBars * $timeframeMinutes))
        );
    }

    /**
     * The threshold is applied HERE rather than inside the strategy, so a
     * sweep can vary it without writing a config version per candidate.
     */
    private function qualifies(SignalResult $result, float $minScore): bool
    {
        if ($result->direction === null || $result->entryPrice === null || $result->stopLoss === null) {
            return false;
        }

        // A failed gate is a hard rejection whatever the total score — the
        // same rule the live engine applies.
        if ($result->hasFailedGate()) {
            return false;
        }

        return $result->score >= $minScore && ($result->riskDistance() ?? 0.0) > 0.0;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private function window(int $instrumentId, \GoldBot\Domain\Market\Timeframe $timeframe, array $options): array
    {
        $utc = new DateTimeZone('UTC');

        $oldest = $this->candles->latest($instrumentId, $timeframe->id, 100000, closedOnly: true)->first();
        $newest = $this->candles->mostRecent($instrumentId, $timeframe->id, closedOnly: true);

        if ($oldest === null || $newest === null) {
            throw new RuntimeException('No candles stored for that instrument and timeframe.');
        }

        $from = ($options['from'] ?? null) instanceof DateTimeImmutable
            ? $options['from']
            // Default: skip the warm-up bars. Evaluating from the very first
            // stored bar would measure a strategy whose indicators have not
            // converged, which is a property of the data, not the strategy.
            // Scaled by the timeframe — 250 bars is 62 hours on M15 and 250
            // days on D1, and hardcoding one of those is wrong for the other.
            : $oldest->openTime->modify(sprintf('+%d minutes', self::WARM_UP_BARS * $timeframe->minutes));

        $to = ($options['to'] ?? null) instanceof DateTimeImmutable ? $options['to'] : $newest->closeTime;

        if ($from >= $to) {
            throw new RuntimeException('The period ends before it begins.');
        }

        return [$from->setTimezone($utc), $to->setTimezone($utc)];
    }

    /** @param array<string,mixed> $options */
    private function configFor(int $strategyId, array $options): StrategyConfig
    {
        if (isset($options['config_version'])) {
            foreach ($this->strategies->configHistory($strategyId) as $row) {
                if ((int) $row['version'] === (int) $options['config_version']) {
                    $config = $this->strategies->configById((int) $row['id']);

                    if ($config !== null) {
                        return $config;
                    }
                }
            }

            throw new RuntimeException('No such config version for that strategy.');
        }

        $config = $this->strategies->activeConfig($strategyId);

        if ($config === null) {
            throw new RuntimeException('That strategy has no active configuration.');
        }

        return $config;
    }

    private function instantiate(string $class): StrategyInterface
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Strategy class {$class} does not exist.");
        }

        /** @var StrategyInterface $instance */
        $instance = $this->container->get($class);

        return $instance;
    }
}
