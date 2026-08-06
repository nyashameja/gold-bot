<?php

declare(strict_types=1);

namespace GoldBot\Domain\Session;

use DateTimeImmutable;

/**
 * Determines which trading sessions are open at a given moment.
 *
 * Sessions overlap — the London/New York overlap is the highest-liquidity
 * window of the gold day — so this returns a list rather than a single value.
 * Signals are tagged with the session they were generated in, which is what
 * makes the per-session performance breakdown (docs/02 §9) meaningful.
 *
 * Pure: no database, no clock, no I/O. The moment is always passed in.
 */
final class SessionResolver
{
    /** @var list<TradingSession> */
    private readonly array $sessions;

    /** @param list<TradingSession> $sessions */
    public function __construct(array $sessions)
    {
        $this->sessions = array_values($sessions);
    }

    /**
     * Build from seeded `market_sessions` rows.
     *
     * @param list<array{code:string,name:string,open_time:string,close_time:string,timezone:string}> $rows
     */
    public static function fromRows(array $rows): self
    {
        return new self(array_map(
            static fn (array $r): TradingSession => new TradingSession(
                $r['code'],
                $r['name'],
                $r['open_time'],
                $r['close_time'],
                $r['timezone']
            ),
            $rows
        ));
    }

    /** @return list<TradingSession> Sessions open at $moment, in definition order. */
    public function activeAt(DateTimeImmutable $moment): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (TradingSession $s): bool => $s->isOpenAt($moment)
        ));
    }

    /** @return list<string> Codes of the sessions open at $moment. */
    public function activeCodesAt(DateTimeImmutable $moment): array
    {
        return array_map(
            static fn (TradingSession $s): string => $s->code,
            $this->activeAt($moment)
        );
    }

    /**
     * The single session a signal should be attributed to.
     *
     * When sessions overlap the last one in definition order wins, which
     * orders Sydney → Tokyo → London → New York and so attributes the
     * London/New York overlap to New York. That is the convention gold traders
     * use, and it must be applied consistently or the session breakdown
     * compares unlike periods.
     */
    public function primaryAt(DateTimeImmutable $moment): ?TradingSession
    {
        $active = $this->activeAt($moment);

        return $active === [] ? null : $active[count($active) - 1];
    }

    public function isAnyOpenAt(DateTimeImmutable $moment): bool
    {
        return $this->activeAt($moment) !== [];
    }

    /** @return list<TradingSession> */
    public function all(): array
    {
        return $this->sessions;
    }
}
