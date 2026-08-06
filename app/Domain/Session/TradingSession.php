<?php

declare(strict_types=1);

namespace GoldBot\Domain\Session;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A trading session, defined in its own local time.
 *
 * Local time plus an IANA zone, never a fixed UTC offset (docs/02 §4). London
 * and New York change to and from DST on different dates, so a session stored
 * as "12:00-21:00 UTC" is wrong for several weeks a year — and the symptom is
 * not an error but a quietly mislabelled performance breakdown months later.
 *
 * Immutable and free of I/O, so it belongs to the Domain layer (ADR-03).
 */
final class TradingSession
{
    private readonly DateTimeZone $zone;

    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $openTime,
        public readonly string $closeTime,
        string $timezone
    ) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $openTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $closeTime)) {
            throw new InvalidArgumentException(
                "Session [{$code}] times must be HH:MM or HH:MM:SS."
            );
        }

        try {
            $this->zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException(
                "Session [{$code}] has an unknown IANA timezone [{$timezone}]."
            );
        }
    }

    public function timezone(): DateTimeZone
    {
        return $this->zone;
    }

    /**
     * True when this session is open at the given moment.
     *
     * The moment is converted into the session's own zone, so DST is applied
     * by the timezone database rather than by arithmetic here.
     */
    public function isOpenAt(DateTimeImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->zone);
        $minutes = ((int) $local->format('G') * 60) + (int) $local->format('i');

        $open = $this->toMinutes($this->openTime);
        $close = $this->toMinutes($this->closeTime);

        // A session whose close is earlier than its open spans local midnight.
        return $open <= $close
            ? $minutes >= $open && $minutes < $close
            : $minutes >= $open || $minutes < $close;
    }

    /** The session's current UTC offset in minutes, at the given moment. */
    public function utcOffsetMinutes(DateTimeImmutable $moment): int
    {
        return intdiv($this->zone->getOffset($moment->setTimezone($this->zone)), 60);
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
