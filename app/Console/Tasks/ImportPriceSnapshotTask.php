<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Integrations\MarketData\MarketDataException;
use GoldBot\Integrations\MarketData\TwelveData\TwelveDataProvider;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Services\MarketData\CandleIngestService;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Captures the current quote for each active instrument.
 *
 * Runs on the tightest cadence of any task, since it drives the live price
 * widget and — from Phase 7 — signal lifecycle tracking. It is also the
 * largest consumer of API budget, which is why the gate is checked before
 * every instrument rather than once per run.
 */
final class ImportPriceSnapshotTask implements TaskInterface
{
    public function __construct(
        private readonly CandleIngestService $ingest,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly ApiBudget $budget,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        $captured = 0;
        $errors = [];

        foreach ($this->reference->activeInstruments() as $instrument) {
            if (!$this->budget->canSpend(TwelveDataProvider::CODE)) {
                return $captured === 0
                    ? TaskResult::skippedBudget()
                    : TaskResult::success($captured, 'Stopped early: API budget exhausted.');
            }

            try {
                $this->ingest->importQuote($instrument['id']);
                $captured++;
            } catch (MarketDataException $e) {
                $errors[] = sprintf('%s: %s', $instrument['symbol'], $e->getMessage());

                $this->logger->warning('Quote capture failed', [
                    'event'      => 'market.quote_failed',
                    'instrument' => $instrument['symbol'],
                    'retryable'  => $e->retryable,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($captured === 0 && $errors !== []) {
            return TaskResult::failed(implode('; ', $errors));
        }

        return TaskResult::success($captured, sprintf('%d quote(s) captured.', $captured));
    }
}
