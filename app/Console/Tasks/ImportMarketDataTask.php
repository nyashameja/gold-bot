<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Domain\Market\Timeframe;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\MarketData\MarketDataException;
use GoldBot\Integrations\MarketData\TwelveData\TwelveDataProvider;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Services\MarketData\CandleIngestService;

/**
 * Imports candles for every active instrument and timeframe.
 *
 * Fetching is aligned to candle close rather than a fixed clock (docs/01 §5):
 * requesting daily bars every minute spends quota to learn nothing. A
 * timeframe is only fetched when its most recent stored bar is older than one
 * full period, so the run is cheap on the minutes where nothing has closed.
 */
final class ImportMarketDataTask implements TaskInterface
{
    public function __construct(
        private readonly CandleIngestService $ingest,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly ApiBudget $budget,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        /** @var array<string,int> Settle margin per timeframe code. */
        private readonly array $settleSeconds = []
    ) {
    }

    public function run(): TaskResult
    {
        if (!$this->budget->canSpend(TwelveDataProvider::CODE)) {
            return TaskResult::skippedBudget();
        }

        $processed = 0;
        $imported = 0;
        $errors = [];

        foreach ($this->reference->activeInstruments() as $instrument) {
            foreach ($this->reference->activeTimeframes() as $timeframe) {
                if (!$this->isDue($instrument['id'], $timeframe)) {
                    continue;
                }

                if (!$this->budget->canSpend(TwelveDataProvider::CODE)) {
                    // Stop cleanly rather than letting the remaining
                    // timeframes each fail against the limit.
                    $this->logger->notice('Stopping import: budget exhausted mid-run', [
                        'event'     => 'api.budget_daily',
                        'processed' => $processed,
                    ]);

                    break 2;
                }

                try {
                    $result = $this->ingest->importLatest($instrument['id'], $timeframe);
                    $imported += $result['inserted'];
                    $processed++;
                } catch (MarketDataException $e) {
                    $errors[] = sprintf('%s %s: %s', $instrument['symbol'], $timeframe->code, $e->getMessage());

                    $this->logger->warning('Candle import failed', [
                        'event'      => 'market.import_failed',
                        'instrument' => $instrument['symbol'],
                        'timeframe'  => $timeframe->code,
                        'retryable'  => $e->retryable,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($errors !== [] && $processed === 0) {
            return TaskResult::failed(implode('; ', $errors));
        }

        // A partial success is still a success: one timeframe failing must not
        // mark the whole task failed and inflate consecutive_failures, or a
        // single bad symbol would trip the health alert for everything.
        return TaskResult::success(
            $processed,
            sprintf(
                '%d series updated, %d new bars%s',
                $processed,
                $imported,
                $errors === [] ? '' : sprintf(', %d error(s): %s', count($errors), implode('; ', $errors))
            )
        );
    }

    /**
     * Whether enough time has passed for a new bar to exist.
     *
     * Compares the newest stored bar's open time against the current candle
     * boundary. Equal means nothing has closed since the last fetch.
     */
    private function isDue(int $instrumentId, Timeframe $timeframe): bool
    {
        $newest = $this->latestOpenTime($instrumentId, $timeframe);

        if ($newest === null) {
            return true; // Nothing stored yet.
        }

        $settle = $this->settleSeconds[$timeframe->code] ?? 0;
        $now = $this->clock->now();

        // The most recent bar that should have closed and settled by now.
        $currentBoundary = $timeframe->candleOpenFor($now->modify(sprintf('-%d seconds', $settle)));

        return $newest < $currentBoundary;
    }

    private function latestOpenTime(int $instrumentId, Timeframe $timeframe): ?\DateTimeImmutable
    {
        return $this->ingest->newestStoredOpenTime($instrumentId, $timeframe);
    }
}
