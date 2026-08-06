<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use GoldBot\Domain\Market\Timeframe;

/**
 * Instruments and timeframes — small, stable reference data read on every
 * ingest run, so implementations are expected to memoise.
 */
interface MarketReferenceRepositoryInterface
{
    /** @return array{id:int,symbol:string,provider_symbol:string,price_precision:int}|null */
    public function instrumentBySymbol(string $symbol): ?array;

    /** @return array{id:int,symbol:string,provider_symbol:string,price_precision:int}|null */
    public function instrumentById(int $id): ?array;

    /** @return list<array{id:int,symbol:string,provider_symbol:string,price_precision:int}> */
    public function activeInstruments(): array;

    public function timeframeByCode(string $code): ?Timeframe;

    public function timeframeById(int $id): ?Timeframe;

    /** @return list<Timeframe> Active timeframes, in display order. */
    public function activeTimeframes(): array;
}
