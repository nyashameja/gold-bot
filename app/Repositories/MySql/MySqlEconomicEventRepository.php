<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Core\Database;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;

final class MySqlEconomicEventRepository implements EconomicEventRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function upsertMany(array $events, DateTimeImmutable $seenAt): array
    {
        if ($events === []) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $before = $this->count();
        $seen = $seenAt->format('Y-m-d H:i:s');

        $this->database->transaction(function () use ($events, $seen): void {
            foreach ($events as $event) {
                $this->database->upsert(
                    'economic_events',
                    [
                        'source'              => $event->source,
                        'provider_event_id'   => $event->providerEventId,
                        'category_id'         => $event->categoryId,
                        'country'             => $event->country,
                        'currency'            => $event->currency,
                        'title'               => $event->title,
                        'impact'              => $event->impact->value,
                        'scheduled_at'        => $event->scheduledAt->format('Y-m-d H:i:s'),
                        'time_is_approximate' => $event->timeIsApproximate ? 1 : 0,
                        'actual'              => $event->actual,
                        'forecast'            => $event->forecast,
                        'previous'            => $event->previous,
                        'revised_from'        => $event->revisedFrom,
                        'unit'                => $event->unit,
                        'detail_url'          => $event->detailUrl,
                        'first_seen_at'       => $seen,
                        'last_seen_at'        => $seen,
                        'retired_at'          => null,
                    ],
                    // first_seen_at is deliberately absent: it records when we
                    // first observed the event and must survive every re-poll.
                    // retired_at is reset, so an event that reappears is
                    // revived rather than staying retired.
                    [
                        'category_id', 'country', 'title', 'impact', 'time_is_approximate',
                        'actual', 'forecast', 'previous', 'revised_from', 'unit', 'detail_url',
                        'last_seen_at', 'retired_at',
                    ]
                );
            }
        });

        $after = $this->count();
        $inserted = $after - $before;

        return ['inserted' => $inserted, 'updated' => count($events) - $inserted];
    }

    public function retireMissing(
        string $source,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $seenProviderIds,
        DateTimeImmutable $retiredAt
    ): int {
        $bindings = [
            $retiredAt->format('Y-m-d H:i:s'),
            $source,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ];

        $notIn = '';

        if ($seenProviderIds !== []) {
            $notIn = ' AND provider_event_id NOT IN (' . implode(',', array_fill(0, count($seenProviderIds), '?')) . ')';
            $bindings = [...$bindings, ...$seenProviderIds];
        }

        // Released events are never retired — they are history, and the whole
        // point of the archive (ADR-15).
        return $this->database->run(
            "UPDATE economic_events
             SET retired_at = ?
             WHERE source = ?
               AND scheduled_at >= ? AND scheduled_at <= ?
               AND retired_at IS NULL
               AND (actual IS NULL OR actual = '')" . $notIn,
            $bindings
        );
    }

    public function between(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $currencies = [],
        ?string $minimumImpact = null
    ): array {
        $sql = 'SELECT * FROM economic_events
                WHERE scheduled_at >= ? AND scheduled_at <= ? AND retired_at IS NULL';
        $bindings = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        [$sql, $bindings] = $this->applyFilters($sql, $bindings, $currencies, $minimumImpact);

        return array_map(
            $this->hydrate(...),
            $this->database->select($sql . ' ORDER BY scheduled_at, id', $bindings)
        );
    }

    public function nextUpcoming(DateTimeImmutable $after, array $currencies = [], ?string $minimumImpact = null): ?EconomicEvent
    {
        $sql = 'SELECT * FROM economic_events WHERE scheduled_at > ? AND retired_at IS NULL';
        $bindings = [$after->format('Y-m-d H:i:s')];

        [$sql, $bindings] = $this->applyFilters($sql, $bindings, $currencies, $minimumImpact);

        $row = $this->database->selectOne($sql . ' ORDER BY scheduled_at LIMIT 1', $bindings);

        return $row === null ? null : $this->hydrate($row);
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM economic_events');
    }

    public function earliestScheduledAt(): ?DateTimeImmutable
    {
        $value = $this->database->scalar('SELECT MIN(scheduled_at) FROM economic_events');

        return $value === null ? null : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    /**
     * @param list<mixed>  $bindings
     * @param list<string> $currencies
     * @return array{0:string,1:list<mixed>}
     */
    private function applyFilters(string $sql, array $bindings, array $currencies, ?string $minimumImpact): array
    {
        if ($currencies !== []) {
            $sql .= ' AND currency IN (' . implode(',', array_fill(0, count($currencies), '?')) . ')';
            $bindings = [...$bindings, ...array_map('strtoupper', $currencies)];
        }

        if ($minimumImpact !== null) {
            // Impact is a string column, so "at least MEDIUM" is expressed as
            // an explicit set rather than an inequality — an ordering that
            // does not exist alphabetically (HIGH < LOW < MEDIUM).
            $allowed = match (strtoupper($minimumImpact)) {
                'HIGH'   => ['HIGH', 'HOLIDAY'],
                'MEDIUM' => ['HIGH', 'HOLIDAY', 'MEDIUM'],
                default  => ['HIGH', 'HOLIDAY', 'MEDIUM', 'LOW'],
            };

            $sql .= ' AND impact IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $bindings = [...$bindings, ...$allowed];
        }

        return [$sql, $bindings];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): EconomicEvent
    {
        return new EconomicEvent(
            providerEventId:   (string) $row['provider_event_id'],
            source:            (string) $row['source'],
            currency:          (string) $row['currency'],
            title:             (string) $row['title'],
            impact:            EventImpact::from((string) $row['impact']),
            scheduledAt:       new DateTimeImmutable((string) $row['scheduled_at'], new DateTimeZone('UTC')),
            timeIsApproximate: (int) $row['time_is_approximate'] === 1,
            country:           $row['country'] === null ? null : (string) $row['country'],
            actual:            $row['actual'] === null ? null : (string) $row['actual'],
            forecast:          $row['forecast'] === null ? null : (string) $row['forecast'],
            previous:          $row['previous'] === null ? null : (string) $row['previous'],
            revisedFrom:       $row['revised_from'] === null ? null : (string) $row['revised_from'],
            unit:              $row['unit'] === null ? null : (string) $row['unit'],
            detailUrl:         $row['detail_url'] === null ? null : (string) $row['detail_url'],
            id:                (int) $row['id'],
            categoryId:        $row['category_id'] === null ? null : (int) $row['category_id']
        );
    }
}
