<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use Paragon\Core\Clock\ClockInterface;

/**
 * The Signals list and the single-signal detail view.
 *
 * The list is filtered and paginated in SQL. Two queries produce a page of
 * rows complete with their targets — the naive shape (fetch signals, then
 * fetch each one's targets) is the classic index-page N+1 and shows up as a
 * page that gets slower every week the system runs.
 */
final class SignalBoardService
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly SignalRepositoryInterface $signals,
        private readonly StrategyRepositoryInterface $strategies,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @param array<string,mixed> $filters Raw request input; sanitised here.
     * @return array<string,mixed>
     */
    public function page(array $filters, int $page = 1): array
    {
        $clean = $this->sanitise($filters);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $total = $this->signals->countMatching($clean);
        $rows = $this->signals->paginate($clean, self::PER_PAGE, $offset);

        $targets = $this->signals->targetsFor(array_map(
            static fn (array $s): int => (int) $s['id'],
            $rows
        ));

        $now = $this->clock->now();

        $items = array_map(
            fn (array $row): array => $this->decorateRow($row, $targets[(int) $row['id']] ?? [], $now),
            $rows
        );

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'items'   => $items,
            'filters' => $clean,
            'options' => $this->filterOptions(),
            'page'    => [
                'current'   => $page,
                'last'      => $lastPage,
                'total'     => $total,
                'per_page'  => self::PER_PAGE,
                'from'      => $total === 0 ? 0 : $offset + 1,
                'to'        => min($offset + self::PER_PAGE, $total),
                'has_prev'  => $page > 1,
                'has_next'  => $page < $lastPage,
            ],
        ];
    }

    /**
     * A single signal with its full audit trail: targets, pillar scores and
     * every event in order.
     *
     * The event log is the record; the state column is a projection of it
     * (ADR-05). Showing both is what makes an unexpected outcome explainable
     * after the fact rather than a mystery.
     *
     * @return array<string,mixed>|null
     */
    public function detail(string $uuid): ?array
    {
        $signal = $this->signals->findByUuid($uuid);

        if ($signal === null) {
            return null;
        }

        $signalId = (int) $signal['id'];
        $now = $this->clock->now();
        $targets = $this->signals->targets($signalId);

        $scores = array_map(
            static function (array $row): array {
                $detail = $row['detail'] === null ? [] : json_decode((string) $row['detail'], true);

                return [
                    'pillar'   => (string) $row['pillar'],
                    'raw'      => (float) $row['raw_score'],
                    'weight'   => (float) $row['weight'],
                    'weighted' => (float) $row['weighted_score'],
                    'passed'   => (bool) $row['passed'],
                    'detail'   => is_array($detail) ? $detail : [],
                ];
            },
            $this->signals->scores($signalId)
        );

        $events = array_map(
            static function (array $row): array {
                $type = SignalEventType::tryFrom((string) $row['event_type']);

                return [
                    'type'        => (string) $row['event_type'],
                    'label'       => $type === null ? (string) $row['event_type'] : $type->label(),
                    'price'       => $row['price_at_event'] === null ? null : (float) $row['price_at_event'],
                    'notes'       => $row['notes'] === null ? null : (string) $row['notes'],
                    'triggered_by' => (string) $row['triggered_by'],
                    'at'          => (string) $row['occurred_at'],
                ];
            },
            $this->signals->events($signalId)
        );

        $base = $this->decorateRow($signal, $targets, $now);
        $snapshot = $this->snapshots->latest((int) $signal['instrument_id']);

        return [
            ...$base,
            'scores'      => $scores,
            'events'      => $events,
            'market_regime' => $signal['market_regime'] === null ? null : (string) $signal['market_regime'],
            // Live P&L only means something while the position is open; on a
            // closed signal the realised figure is the answer and a mark-to-
            // market number beside it would just be noise.
            'unrealised'  => $base['is_open'] && $snapshot !== null
                ? $this->unrealised($base, (float) $snapshot->price)
                : null,
            'last_price'  => $snapshot === null ? null : (float) $snapshot->price,
            'price_age'   => DataAge::since($snapshot?->capturedAt, $now, 60)->toArray(),
        ];
    }

    /**
     * The open-positions strip shown on the Overview.
     *
     * @return list<array<string,mixed>>
     */
    public function openSignals(int $limit = 5): array
    {
        $rows = $this->signals->paginate(['open_only' => true], $limit, 0);

        $targets = $this->signals->targetsFor(array_map(
            static fn (array $s): int => (int) $s['id'],
            $rows
        ));

        $now = $this->clock->now();

        return array_map(
            fn (array $row): array => $this->decorateRow($row, $targets[(int) $row['id']] ?? [], $now),
            $rows
        );
    }

    /**
     * Normalise request input into the repository's filter shape.
     *
     * Anything unrecognised is dropped rather than passed through: a filter
     * the repository does not understand must not become a silent no-op that
     * shows the user unfiltered data as though it were filtered.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function sanitise(array $filters): array
    {
        $clean = [];

        $state = is_string($filters['state'] ?? null) ? strtoupper($filters['state']) : null;

        if ($state === 'OPEN') {
            $clean['open_only'] = true;
        } elseif ($state !== null && SignalState::tryFrom($state) !== null) {
            $clean['state'] = $state;
        }

        $direction = is_string($filters['direction'] ?? null) ? strtoupper($filters['direction']) : null;

        if ($direction !== null && Direction::tryFrom($direction) !== null) {
            $clean['direction'] = $direction;
        }

        if (is_string($filters['timeframe'] ?? null) && $filters['timeframe'] !== '') {
            $timeframe = $this->reference->timeframeByCode($filters['timeframe']);

            if ($timeframe !== null) {
                $clean['timeframe_id'] = $timeframe->id;
            }
        }

        if (is_string($filters['strategy'] ?? null) && $filters['strategy'] !== '') {
            $strategy = $this->strategies->findByCode($filters['strategy']);

            if ($strategy !== null) {
                $clean['strategy_id'] = (int) $strategy['id'];
            }
        }

        $days = isset($filters['days']) ? (int) $filters['days'] : 0;

        if ($days > 0) {
            $clean['since'] = $this->clock->now()->modify("-{$days} days");
        }

        return $clean;
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private function filterOptions(): array
    {
        $states = [['value' => 'OPEN', 'label' => 'Open (any)']];

        foreach (SignalState::cases() as $state) {
            $states[] = ['value' => $state->value, 'label' => $state->label()];
        }

        return [
            'states'     => $states,
            'directions' => [
                ['value' => Direction::Buy->value, 'label' => 'Buy'],
                ['value' => Direction::Sell->value, 'label' => 'Sell'],
            ],
            'timeframes' => array_map(
                static fn (\GoldBot\Domain\Market\Timeframe $t): array => ['value' => $t->code, 'label' => $t->code],
                $this->reference->activeTimeframes()
            ),
            'strategies' => array_map(
                static fn (array $s): array => ['value' => (string) $s['code'], 'label' => (string) $s['name']],
                $this->strategies->enabled()
            ),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $targets
     * @return array<string,mixed>
     */
    private function decorateRow(array $row, array $targets, DateTimeImmutable $now): array
    {
        $state = SignalState::from((string) $row['state']);
        $direction = Direction::from((string) $row['direction']);
        $generatedAt = new DateTimeImmutable((string) $row['generated_at'], new DateTimeZone('UTC'));

        return [
            'uuid'          => (string) $row['uuid'],
            'direction'     => $direction->value,
            'is_buy'        => $direction->isBuy(),
            'state'         => $state->value,
            'state_label'   => $state->label(),
            'state_tone'    => $this->tone($state),
            'is_open'       => $state->isOpen(),
            'score'         => (float) $row['score'],
            'entry'         => (float) $row['entry_price'],
            'stop'          => (float) $row['stop_loss'],
            'risk_distance' => (float) $row['risk_distance'],
            'risk_reward'   => $row['risk_reward'] === null ? null : (float) $row['risk_reward'],
            'realised_r'    => $row['realised_r'] === null ? null : (float) $row['realised_r'],
            'strategy'      => (string) ($row['strategy_code'] ?? ''),
            'strategy_name' => (string) ($row['strategy_name'] ?? $row['strategy_code'] ?? ''),
            'timeframe'     => (string) ($row['timeframe_code'] ?? ''),
            'session'       => $row['session_code'] === null ? null : (string) $row['session_code'],
            'generated_at'  => $generatedAt->format(DATE_ATOM),
            'activated_at'  => $row['activated_at'] === null ? null : (string) $row['activated_at'],
            'closed_at'     => $row['closed_at'] === null ? null : (string) $row['closed_at'],
            'expires_at'    => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'close_reason'  => $row['close_reason'] === null ? null : (string) $row['close_reason'],
            'age'           => DataAge::since($generatedAt, $now, 3600)->toArray(),
            'targets'       => array_map(
                static fn (array $t): array => [
                    'level'         => (int) $t['level'],
                    'price'         => (float) $t['price'],
                    'close_percent' => $t['close_percent'] === null ? null : (float) $t['close_percent'],
                    'r_multiple'    => $t['r_multiple'] === null ? null : (float) $t['r_multiple'],
                    'hit'           => $t['hit_at'] !== null,
                    'hit_at'        => $t['hit_at'] === null ? null : (string) $t['hit_at'],
                ],
                $targets
            ),
        ];
    }

    /**
     * Mark-to-market in R, so it is directly comparable with the realised
     * figures elsewhere on the dashboard.
     *
     * @param array<string,mixed> $signal
     * @return array{r:float,price_move:float}
     */
    private function unrealised(array $signal, float $lastPrice): array
    {
        $entry = (float) $signal['entry'];
        $risk = (float) $signal['risk_distance'];
        $sign = $signal['is_buy'] === true ? 1 : -1;
        $move = ($lastPrice - $entry) * $sign;

        return [
            'r'          => $risk > 0.0 ? round($move / $risk, 3) : 0.0,
            'price_move' => round($move, 5),
        ];
    }

    private function tone(SignalState $state): string
    {
        return match ($state) {
            SignalState::ClosedWin => 'bull',
            SignalState::ClosedLoss => 'bear',
            SignalState::Active, SignalState::Breakeven => 'gold',
            default => 'neutral',
        };
    }
}
