<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals;

use DateTimeImmutable;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Domain\Strategy\StrategyInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use GoldBot\Services\Signals\Filters\SignalFilterChain;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Container;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Evaluates enabled strategies against new closed candles and publishes what
 * qualifies (docs/01 §6).
 *
 * Two properties are load-bearing:
 *
 *   1. EVERY evaluation is recorded, not only the ones that fire. That is what
 *      answers "why did nothing fire today?", what makes threshold tuning
 *      empirical, and what accumulates the dataset for any future ML work —
 *      none of which can be reconstructed after the fact.
 *   2. Evaluation is incremental and idempotent. The strategy watermark tracks
 *      the last candle processed, and strategy_runs is uniquely keyed by
 *      candle, so re-running over a window cannot double-publish.
 */
final class SignalEngine
{
    public function __construct(
        private readonly Container $container,
        private readonly StrategyRepositoryInterface $strategies,
        private readonly CandleRepositoryInterface $candles,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly WatermarkRepositoryInterface $watermarks,
        private readonly SettingsRepositoryInterface $settings,
        private readonly StrategyContextBuilder $contexts,
        private readonly SignalFilterChain $filters,
        private readonly SignalPublisher $publisher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run every enabled strategy over whatever is new.
     *
     * @return array{evaluated:int,published:int,rejected:int,errors:list<string>}
     */
    public function run(): array
    {
        $evaluated = 0;
        $published = 0;
        $rejected = 0;
        $errors = [];

        foreach ($this->strategies->enabled() as $strategy) {
            $config = $this->strategies->activeConfig($strategy['id']);

            if ($config === null) {
                // A strategy with no activated config is misconfigured, not
                // broken — skip it loudly rather than failing the whole run.
                $this->logger->warning('Strategy has no active configuration', [
                    'event'    => 'signal.no_config',
                    'strategy' => $strategy['code'],
                ]);

                continue;
            }

            try {
                $instance = $this->container->get($strategy['class_name']);

                if (!$instance instanceof StrategyInterface) {
                    $errors[] = $strategy['code'] . ': class does not implement StrategyInterface';

                    continue;
                }

                $outcome = $this->runStrategy($instance, $strategy, $config);

                $evaluated += $outcome['evaluated'];
                $published += $outcome['published'];
                $rejected += $outcome['rejected'];
            } catch (Throwable $e) {
                // One strategy failing must not stop the others.
                $errors[] = $strategy['code'] . ': ' . $e->getMessage();

                $this->logger->error('Strategy evaluation failed', [
                    'event'     => 'signal.strategy_failed',
                    'strategy'  => $strategy['code'],
                    'exception' => $e,
                ]);
            }
        }

        return [
            'evaluated' => $evaluated,
            'published' => $published,
            'rejected'  => $rejected,
            'errors'    => $errors,
        ];
    }

    /**
     * @param array{id:int,code:string,name:string,class_name:string} $definition
     * @return array{evaluated:int,published:int,rejected:int}
     */
    private function runStrategy(StrategyInterface $strategy, array $definition, StrategyConfig $config): array
    {
        $signalTimeframeCode = strtoupper($config->string('signal_timeframe', 'M15'));
        $timeframe = $this->reference->timeframeByCode($signalTimeframeCode);

        if ($timeframe === null) {
            throw new \RuntimeException("Unknown signal timeframe [{$signalTimeframeCode}].");
        }

        $required = $strategy->requiredTimeframes($config);
        $evaluated = 0;
        $published = 0;
        $rejected = 0;

        foreach ($this->reference->activeInstruments() as $instrument) {
            $stage = WatermarkRepositoryInterface::STAGE_STRATEGY;
            $last = $this->watermarks->lastProcessed($instrument['id'], $timeframe->id, $stage);

            $pending = $this->candles->closedSince($instrument['id'], $timeframe->id, $last, 200);

            foreach ($pending as $candle) {
                $startedAt = microtime(true);

                $context = $this->contexts->build(
                    $instrument['id'],
                    $timeframe,
                    $required,
                    $candle->closeTime
                );

                if ($context === null) {
                    continue;
                }

                $result = $strategy->evaluate($context, $config);
                $reason = $result->rejectionReason;

                // Filters run only on a setup the strategy accepted: there is
                // no point asking whether a non-signal is publishable, and
                // doing so would overwrite the strategy's own reason.
                if ($result->qualified) {
                    $reason = $this->filters->reject($result, $context);
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $willPublish = $result->qualified && $reason === null;

                $runId = $this->strategies->recordRun(
                    $definition['id'],
                    $config->id,
                    $instrument['id'],
                    $timeframe->id,
                    $candle->id,
                    $candle->openTime,
                    $this->clock->now(),
                    $result->direction?->value,
                    $result->score,
                    $willPublish,
                    $reason,
                    $context->toFeatures(),
                    $durationMs
                );

                $evaluated++;

                if ($willPublish) {
                    $this->publish($result, $definition, $config, $context, $runId, $timeframe);
                    $published++;
                } else {
                    $rejected++;
                }

                $this->watermarks->advance($instrument['id'], $timeframe->id, $stage, $candle->openTime, $candle->id);
            }
        }

        return ['evaluated' => $evaluated, 'published' => $published, 'rejected' => $rejected];
    }

    /**
     * @param array{id:int,code:string,name:string,class_name:string} $strategy
     */
    private function publish(
        SignalResult $result,
        array $strategy,
        StrategyConfig $config,
        StrategyContext $context,
        int $runId,
        Timeframe $timeframe
    ): void {
        $expiryMinutes = (int) $this->settings->get('signals.expiry_minutes', 240);

        // Delegated so the signal row and its outbound message are written in
        // one transaction (ADR-07). Sending inline from here could produce a
        // signal with no alert, or an alert for a signal that rolled back.
        $this->publisher->publish(
            $result,
            $strategy,
            $config,
            $context,
            $runId,
            $timeframe->id,
            $expiryMinutes > 0 ? $context->at->modify(sprintf('+%d minutes', $expiryMinutes)) : null
        );
    }
}
