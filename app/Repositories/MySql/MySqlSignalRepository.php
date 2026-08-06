<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalLifecycle;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Support\Uuid;

final class MySqlSignalRepository implements \GoldBot\Repositories\Contracts\SignalRepositoryInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly SignalLifecycle $lifecycle
    ) {
    }

    public function create(
        SignalResult $result,
        int $strategyId,
        int $configId,
        ?int $runId,
        int $instrumentId,
        int $timeframeId,
        DateTimeImmutable $generatedAt,
        ?DateTimeImmutable $expiresAt,
        ?string $sessionCode,
        ?string $marketRegime
    ): int {
        if ($result->direction === null || $result->entryPrice === null || $result->stopLoss === null) {
            throw new \InvalidArgumentException('Cannot persist a signal without direction, entry and stop.');
        }

        // One transaction: a signal without its scores cannot explain itself,
        // and targets without a signal are orphans.
        return $this->database->transaction(function () use (
            $result,
            $strategyId,
            $configId,
            $runId,
            $instrumentId,
            $timeframeId,
            $generatedAt,
            $expiresAt,
            $sessionCode,
            $marketRegime
        ): int {
            $signalId = $this->database->insert('signals', [
                'uuid'               => Uuid::toBinary(Uuid::v4()),
                'strategy_id'        => $strategyId,
                'strategy_config_id' => $configId,
                'strategy_run_id'    => $runId === 0 ? null : $runId,
                'instrument_id'      => $instrumentId,
                'timeframe_id'       => $timeframeId,
                'direction'          => $result->direction->value,
                'state'              => SignalState::Pending->value,
                'score'              => $result->score,
                'entry_price'        => number_format($result->entryPrice, 5, '.', ''),
                'stop_loss'          => number_format($result->stopLoss, 5, '.', ''),
                'risk_distance'      => number_format((float) $result->riskDistance(), 5, '.', ''),
                'risk_reward'        => $result->riskReward(),
                'session_code'       => $sessionCode,
                'market_regime'      => $marketRegime,
                'generated_at'       => $generatedAt->format('Y-m-d H:i:s'),
                'expires_at'         => $expiresAt?->format('Y-m-d H:i:s'),
            ]);

            foreach ($result->targets as $target) {
                $this->database->insert('signal_targets', [
                    'signal_id'     => $signalId,
                    'level'         => $target->level,
                    'price'         => number_format($target->price, 5, '.', ''),
                    'close_percent' => $target->closePercent,
                    'r_multiple'    => $target->rMultiple,
                ]);
            }

            foreach ($result->pillars as $pillar) {
                $this->database->insert('signal_scores', [
                    'signal_id'      => $signalId,
                    'pillar'         => $pillar->pillar,
                    'raw_score'      => $pillar->raw,
                    'weight'         => $pillar->weight,
                    'weighted_score' => $pillar->weighted(),
                    'passed'         => $pillar->passed ? 1 : 0,
                    'detail'         => json_encode($pillar->detail, JSON_UNESCAPED_SLASHES) ?: null,
                ]);
            }

            $this->database->insert('signal_events', [
                'signal_id'      => $signalId,
                'event_type'     => SignalEventType::Generated->value,
                'price_at_event' => number_format($result->entryPrice, 5, '.', ''),
                'triggered_by'   => 'SYSTEM',
                'occurred_at'    => $generatedAt->format('Y-m-d H:i:s.v'),
            ]);

            return $signalId;
        });
    }

    public function recordEvent(
        int $signalId,
        SignalEventType $event,
        DateTimeImmutable $occurredAt,
        ?float $price = null,
        ?string $notes = null,
        string $triggeredBy = 'SYSTEM',
        ?int $userId = null
    ): bool {
        return $this->database->transaction(function () use (
            $signalId,
            $event,
            $occurredAt,
            $price,
            $notes,
            $triggeredBy,
            $userId
        ): bool {
            $row = $this->database->selectOne('SELECT state FROM signals WHERE id = ?', [$signalId]);

            if ($row === null) {
                return false;
            }

            $current = SignalState::from((string) $row['state']);
            $next = $this->lifecycle->stateAfter($event, $current);

            // An event implying an illegal transition is refused outright — a
            // late tick must not resurrect a closed signal (ADR-05).
            if ($next === null && $this->changesState($event)) {
                return false;
            }

            $this->database->insert('signal_events', [
                'signal_id'      => $signalId,
                'event_type'     => $event->value,
                'price_at_event' => $price === null ? null : number_format($price, 5, '.', ''),
                'notes'          => $notes,
                'triggered_by'   => $triggeredBy,
                'user_id'        => $userId,
                'occurred_at'    => $occurredAt->format('Y-m-d H:i:s.v'),
            ]);

            if ($next !== null) {
                $this->applyState($signalId, $next, $event, $occurredAt);
            }

            return true;
        });
    }

    /** Whether this event type is expected to move the projection. */
    private function changesState(SignalEventType $event): bool
    {
        return match ($event) {
            SignalEventType::Generated, SignalEventType::Sent,
            SignalEventType::Tp1Hit, SignalEventType::Tp2Hit => false,
            default => true,
        };
    }

    private function applyState(int $signalId, SignalState $state, SignalEventType $event, DateTimeImmutable $at): void
    {
        $fields = ['state' => $state->value];

        if ($state === SignalState::Active) {
            $fields['activated_at'] = $at->format('Y-m-d H:i:s');
        }

        if ($state->isClosed()) {
            $fields['closed_at'] = $at->format('Y-m-d H:i:s');
            $fields['close_reason'] = $event->value;
        }

        $assignments = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($fields)));

        $this->database->run(
            "UPDATE signals SET {$assignments} WHERE id = ?",
            [...array_values($fields), $signalId]
        );
    }

    public function find(int $signalId): ?array
    {
        return $this->decorate($this->database->selectOne(
            'SELECT s.*, st.code AS strategy_code, st.name AS strategy_name
             FROM signals s JOIN strategies st ON st.id = s.strategy_id
             WHERE s.id = ?',
            [$signalId]
        ));
    }

    public function findByUuid(string $uuid): ?array
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->decorate($this->database->selectOne(
            'SELECT s.*, st.code AS strategy_code, st.name AS strategy_name
             FROM signals s JOIN strategies st ON st.id = s.strategy_id
             WHERE s.uuid = ?',
            [Uuid::toBinary($uuid)]
        ));
    }

    public function open(?int $instrumentId = null): array
    {
        $sql = "SELECT s.*, st.code AS strategy_code FROM signals s
                JOIN strategies st ON st.id = s.strategy_id
                WHERE s.state IN ('PENDING', 'ACTIVE', 'BREAKEVEN')";
        $bindings = [];

        if ($instrumentId !== null) {
            $sql .= ' AND s.instrument_id = ?';
            $bindings[] = $instrumentId;
        }

        return array_map(
            fn (array $row): array => (array) $this->decorate($row),
            $this->database->select($sql . ' ORDER BY s.generated_at', $bindings)
        );
    }

    public function countOpen(): int
    {
        return (int) $this->database->scalar(
            "SELECT COUNT(*) FROM signals WHERE state IN ('PENDING', 'ACTIVE', 'BREAKEVEN')"
        );
    }

    public function hasOpenInDirection(int $instrumentId, Direction $direction): bool
    {
        return (int) $this->database->scalar(
            "SELECT COUNT(*) FROM signals
             WHERE instrument_id = ? AND direction = ? AND state IN ('PENDING', 'ACTIVE', 'BREAKEVEN')",
            [$instrumentId, $direction->value]
        ) > 0;
    }

    public function countSince(int $instrumentId, Direction $direction, DateTimeImmutable $since): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM signals
             WHERE instrument_id = ? AND direction = ? AND generated_at >= ?',
            [$instrumentId, $direction->value, $since->format('Y-m-d H:i:s')]
        );
    }

    public function recent(int $limit = 50, int $offset = 0): array
    {
        return array_map(
            fn (array $row): array => (array) $this->decorate($row),
            $this->database->select(
                'SELECT s.*, st.code AS strategy_code, st.name AS strategy_name
                 FROM signals s JOIN strategies st ON st.id = s.strategy_id
                 ORDER BY s.generated_at DESC, s.id DESC
                 LIMIT ? OFFSET ?',
                [max(1, min($limit, 200)), max(0, $offset)]
            )
        );
    }

    public function events(int $signalId): array
    {
        return $this->database->select(
            'SELECT event_type, price_at_event, notes, triggered_by, occurred_at
             FROM signal_events WHERE signal_id = ? ORDER BY occurred_at, id',
            [$signalId]
        );
    }

    public function targets(int $signalId): array
    {
        return $this->database->select(
            'SELECT level, price, close_percent, r_multiple, hit_at, hit_price
             FROM signal_targets WHERE signal_id = ? ORDER BY level',
            [$signalId]
        );
    }

    public function scores(int $signalId): array
    {
        return $this->database->select(
            'SELECT pillar, raw_score, weight, weighted_score, passed, detail
             FROM signal_scores WHERE signal_id = ? ORDER BY id',
            [$signalId]
        );
    }

    public function markTargetHit(int $signalId, int $level, DateTimeImmutable $at, float $price): void
    {
        $this->database->run(
            'UPDATE signal_targets SET hit_at = ?, hit_price = ?
             WHERE signal_id = ? AND level = ? AND hit_at IS NULL',
            [$at->format('Y-m-d H:i:s'), number_format($price, 5, '.', ''), $signalId, $level]
        );
    }

    public function updateState(int $signalId, SignalState $state, DateTimeImmutable $at, ?string $closeReason = null, ?float $realisedR = null): void
    {
        $this->database->run(
            'UPDATE signals SET state = ?, closed_at = ?, close_reason = ?, realised_r = ? WHERE id = ?',
            [
                $state->value,
                $state->isClosed() ? $at->format('Y-m-d H:i:s') : null,
                $closeReason,
                $realisedR,
                $signalId,
            ]
        );
    }

    /** @param array<string,mixed>|null $row */
    private function decorate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        if (isset($row['uuid']) && is_string($row['uuid']) && strlen($row['uuid']) === 16) {
            $row['uuid'] = Uuid::toString($row['uuid']);
        }

        return $row;
    }
}
