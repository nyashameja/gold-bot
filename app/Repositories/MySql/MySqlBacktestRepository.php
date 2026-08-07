<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use GoldBot\Core\Database;
use GoldBot\Domain\Backtest\SimulatedTrade;
use GoldBot\Domain\Performance\MetricSet;
use GoldBot\Repositories\Contracts\BacktestRepositoryInterface;
use GoldBot\Support\Uuid;

final class MySqlBacktestRepository implements BacktestRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function store(array $run, ?int $userId = null, ?string $label = null, ?string $notes = null): string
    {
        $uuid = Uuid::v4();

        /** @var MetricSet $metrics */
        $metrics = $run['metrics'];
        /** @var list<SimulatedTrade> $trades */
        $trades = $run['trades'];

        // One transaction: a run without its trades cannot be re-examined, and
        // trades without their run are unattributable.
        $this->database->transaction(function () use ($run, $uuid, $userId, $label, $notes, $metrics, $trades): void {
            $backtestId = $this->database->insert('backtests', [
                'uuid'               => Uuid::toBinary($uuid),
                'label'              => $label,
                'strategy_id'        => $run['strategy_id'],
                'strategy_config_id' => $run['config']->id,
                // Snapshotted, not merely referenced: a run must stay
                // interpretable after its config version is superseded.
                'config_snapshot'    => json_encode($run['config']->all(), JSON_UNESCAPED_SLASHES),
                'instrument_id'      => $run['instrument_id'],
                'timeframe_id'       => $run['timeframe']->id,
                'period_from'        => $run['from']->format('Y-m-d H:i:s'),
                'period_to'          => $run['to']->format('Y-m-d H:i:s'),
                'min_score'          => $run['min_score'],
                'filters_enabled'    => 1,
                'news_filter'        => $run['news_filter'] ? 1 : 0,
                'bars_evaluated'     => $run['evaluated'],
                'signals_generated'  => count($trades),
                'trades_closed'      => $metrics->total,
                'still_open'         => $run['still_open'],
                'wins'               => $metrics->wins,
                'losses'             => $metrics->losses,
                'breakeven'          => $metrics->breakeven,
                'win_rate'           => $metrics->winRate,
                'profit_factor'      => $metrics->profitFactor,
                'expectancy_r'       => $metrics->expectancy,
                'total_r'            => $metrics->totalR,
                'max_drawdown_r'     => $metrics->maxDrawdownR,
                'duration_ms'        => $run['duration_ms'],
                'notes'              => $notes,
                'created_by'         => $userId,
                'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);

            foreach ($trades as $trade) {
                $this->database->insert('backtest_trades', [
                    'backtest_id' => $backtestId,
                    ...$trade->toColumns(),
                ]);
            }
        });

        return $uuid;
    }

    public function recent(int $limit = 25): array
    {
        return array_map(
            $this->decorate(...),
            $this->database->select(
                'SELECT b.*, s.code AS strategy_code, s.name AS strategy_name,
                        t.code AS timeframe_code, u.name AS created_by_name
                 FROM backtests b
                 JOIN strategies s ON s.id = b.strategy_id
                 JOIN timeframes t ON t.id = b.timeframe_id
                 LEFT JOIN users u ON u.id = b.created_by
                 ORDER BY b.created_at DESC, b.id DESC
                 LIMIT ?',
                [max(1, min($limit, 200))]
            )
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        $row = $this->database->selectOne(
            'SELECT b.*, s.code AS strategy_code, s.name AS strategy_name,
                    t.code AS timeframe_code, u.name AS created_by_name
             FROM backtests b
             JOIN strategies s ON s.id = b.strategy_id
             JOIN timeframes t ON t.id = b.timeframe_id
             LEFT JOIN users u ON u.id = b.created_by
             WHERE b.uuid = ?',
            [Uuid::toBinary($uuid)]
        );

        return $row === null ? null : $this->decorate($row);
    }

    public function trades(int $backtestId): array
    {
        return $this->database->select(
            'SELECT * FROM backtest_trades WHERE backtest_id = ? ORDER BY signalled_at, id',
            [$backtestId]
        );
    }

    public function scoreBands(int $backtestId, int $width = 5): array
    {
        $width = max(1, min($width, 25));

        $rows = $this->database->select(
            "SELECT
                FLOOR(score / {$width}) * {$width} AS low,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'WIN' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'LOSS' THEN 1 ELSE 0 END) AS losses,
                COALESCE(SUM(realised_r), 0) AS net_r
             FROM backtest_trades
             WHERE backtest_id = ? AND outcome IN ('WIN', 'LOSS', 'BREAKEVEN')
             GROUP BY low
             ORDER BY low",
            [$backtestId]
        );

        return array_map(
            static fn (array $r): array => [
                'low'    => (int) $r['low'],
                'total'  => (int) $r['total'],
                'wins'   => (int) $r['wins'],
                'losses' => (int) $r['losses'],
                'net_r'  => (float) $r['net_r'],
            ],
            $rows
        );
    }

    public function delete(string $uuid): bool
    {
        if (!Uuid::isValid($uuid)) {
            return false;
        }

        // Trades cascade.
        return $this->database->run('DELETE FROM backtests WHERE uuid = ?', [Uuid::toBinary($uuid)]) > 0;
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM backtests');
    }

    /** @param array<string,mixed> $row */
    private function decorate(array $row): array
    {
        if (isset($row['uuid']) && is_string($row['uuid']) && strlen($row['uuid']) === 16) {
            $row['uuid'] = Uuid::toString($row['uuid']);
        }

        return $row;
    }
}
