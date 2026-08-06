<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Services\MarketData\IndicatorService;
use GoldBot\Services\MarketData\StructureService;
use Throwable;

/**
 * Computes indicators, market structure and levels for new closed candles.
 *
 * Makes no network calls — it works entirely from stored candles, so it costs
 * no API budget and runs regardless of whether the provider is reachable.
 * That separation is why ingest and analysis are distinct tiers (docs/01 §3):
 * a rate-limited fetch cannot leave analysis half-run.
 */
final class CalculateIndicatorsTask implements TaskInterface
{
    public function __construct(
        private readonly IndicatorService $indicators,
        private readonly StructureService $structure,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        $processed = 0;
        $errors = [];

        foreach ($this->reference->activeInstruments() as $instrument) {
            foreach ($this->reference->activeTimeframes() as $timeframe) {
                try {
                    $written = $this->indicators->process($instrument['id'], $timeframe);

                    // Structure and levels only need recomputing when new bars
                    // arrived — they are a function of the same closed candles.
                    if ($written > 0) {
                        $this->structure->process($instrument['id'], $timeframe);
                    }

                    $processed += $written;
                } catch (Throwable $e) {
                    $errors[] = sprintf('%s %s: %s', $instrument['symbol'], $timeframe->code, $e->getMessage());

                    $this->logger->error('Indicator computation failed', [
                        'event'      => 'market.indicators_failed',
                        'instrument' => $instrument['symbol'],
                        'timeframe'  => $timeframe->code,
                        'exception'  => $e,
                    ]);
                }
            }
        }

        if ($errors !== [] && $processed === 0) {
            return TaskResult::failed(implode('; ', $errors));
        }

        return TaskResult::success(
            $processed,
            sprintf(
                '%d bar(s) analysed%s',
                $processed,
                $errors === [] ? '' : sprintf(', %d error(s)', count($errors))
            )
        );
    }
}
