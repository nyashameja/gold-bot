<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use GoldBot\Domain\Market\Timeframe;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use Paragon\Core\Database;

/**
 * Reference data with per-request memoisation.
 *
 * The ingest task resolves the same instrument and timeframes on every
 * iteration; without memoisation that is a handful of identical queries per
 * run, forever.
 */
final class MySqlMarketReferenceRepository implements MarketReferenceRepositoryInterface
{
    /** @var array<string,array<string,mixed>|null> */
    private array $instruments = [];

    /** @var array<string,Timeframe|null> */
    private array $timeframes = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function instrumentBySymbol(string $symbol): ?array
    {
        return $this->instruments['s:' . $symbol] ??= $this->fetchInstrument('symbol = ?', [$symbol]);
    }

    public function instrumentById(int $id): ?array
    {
        return $this->instruments['i:' . $id] ??= $this->fetchInstrument('id = ?', [$id]);
    }

    public function activeInstruments(): array
    {
        $rows = $this->database->select(
            'SELECT id, symbol, provider_symbol, name, price_precision, pip_size
             FROM instruments
             WHERE is_active = 1 AND deleted_at IS NULL
             ORDER BY symbol'
        );

        return array_map($this->castInstrument(...), $rows);
    }

    public function timeframeByCode(string $code): ?Timeframe
    {
        return $this->timeframes['c:' . $code] ??= $this->fetchTimeframe('code = ?', [$code]);
    }

    public function timeframeById(int $id): ?Timeframe
    {
        return $this->timeframes['i:' . $id] ??= $this->fetchTimeframe('id = ?', [$id]);
    }

    public function activeTimeframes(): array
    {
        $rows = $this->database->select(
            'SELECT id, code, minutes, provider_interval, is_active
             FROM timeframes
             WHERE is_active = 1
             ORDER BY sort_order'
        );

        return array_map(static fn (array $r): Timeframe => Timeframe::fromRow($r), $rows);
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string,mixed>|null
     */
    private function fetchInstrument(string $where, array $bindings): ?array
    {
        $row = $this->database->selectOne(
            "SELECT id, symbol, provider_symbol, name, price_precision, pip_size
             FROM instruments
             WHERE {$where} AND deleted_at IS NULL",
            $bindings
        );

        return $row === null ? null : $this->castInstrument($row);
    }

    /** @param list<mixed> $bindings */
    private function fetchTimeframe(string $where, array $bindings): ?Timeframe
    {
        $row = $this->database->selectOne(
            "SELECT id, code, minutes, provider_interval, is_active FROM timeframes WHERE {$where}",
            $bindings
        );

        return $row === null ? null : Timeframe::fromRow($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,symbol:string,provider_symbol:string,name:string,price_precision:int,pip_size:float}
     */
    private function castInstrument(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'symbol'          => (string) $row['symbol'],
            'provider_symbol' => (string) $row['provider_symbol'],
            // Carried for the dashboard, which has to label the instrument
            // with something a human recognises rather than a ticker.
            'name'            => (string) $row['name'],
            'price_precision' => (int) $row['price_precision'],
            'pip_size'        => (float) $row['pip_size'],
        ];
    }
}
