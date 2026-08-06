<?php

declare(strict_types=1);

namespace GoldBot\Domain\Calendar;

use DateTimeImmutable;

/**
 * A scheduled economic release.
 *
 * Immutable and free of I/O (ADR-03). Numeric fields stay strings because
 * providers publish them as formatted text — "175K", "3.2%", "-0.1" — and
 * parsing them into floats would lose the unit and invent precision. Nothing
 * in V1 does arithmetic on them; they are displayed and compared as published.
 */
final class EconomicEvent
{
    public function __construct(
        public readonly string $providerEventId,
        public readonly string $source,
        public readonly string $currency,
        public readonly string $title,
        public readonly EventImpact $impact,
        public readonly DateTimeImmutable $scheduledAt,
        public readonly bool $timeIsApproximate = false,
        public readonly ?string $country = null,
        public readonly ?string $actual = null,
        public readonly ?string $forecast = null,
        public readonly ?string $previous = null,
        public readonly ?string $revisedFrom = null,
        public readonly ?string $unit = null,
        public readonly ?string $detailUrl = null,
        public readonly ?int $id = null,
        public readonly ?int $categoryId = null
    ) {
    }

    /** Whether the figure has been published. */
    public function isReleased(): bool
    {
        return $this->actual !== null && $this->actual !== '';
    }

    public function isUpcoming(DateTimeImmutable $now): bool
    {
        return $this->scheduledAt > $now;
    }

    /**
     * The blackout window around this event.
     *
     * An approximate time — "Tentative", or a day-only FRED release date — is
     * widened, because suppressing a narrow window around a time we do not
     * actually know provides false confidence rather than protection.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public function blackoutWindow(int $minutesBefore, int $minutesAfter, int $approximatePaddingMinutes = 240): array
    {
        $before = $minutesBefore;
        $after = $minutesAfter;

        if ($this->timeIsApproximate) {
            $before = max($before, $approximatePaddingMinutes);
            $after = max($after, $approximatePaddingMinutes);
        }

        return [
            $this->scheduledAt->modify(sprintf('-%d minutes', $before)),
            $this->scheduledAt->modify(sprintf('+%d minutes', $after)),
        ];
    }

    /**
     * Whether $moment falls inside this event's blackout window.
     */
    public function blackoutCovers(
        DateTimeImmutable $moment,
        int $minutesBefore,
        int $minutesAfter,
        int $approximatePaddingMinutes = 240
    ): bool {
        [$from, $to] = $this->blackoutWindow($minutesBefore, $minutesAfter, $approximatePaddingMinutes);

        return $moment >= $from && $moment <= $to;
    }

    /**
     * Whether this event can move the given instrument.
     *
     * Gold is priced in dollars, so USD releases dominate — but it is also a
     * safe-haven asset, which is why a small set of non-USD events (ECB, major
     * geopolitical data) still matters. Callers pass the currencies they care
     * about rather than this class deciding.
     *
     * @param list<string> $currencies
     */
    public function affects(array $currencies): bool
    {
        return in_array(strtoupper($this->currency), array_map('strtoupper', $currencies), true);
    }
}
