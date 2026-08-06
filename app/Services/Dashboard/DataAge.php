<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;

/**
 * How old a piece of displayed data is, and whether that is acceptable.
 *
 * Every widget on this dashboard reads from MySQL, which a cron filled at some
 * earlier moment (docs/01 §8). A price with no timestamp beside it is the most
 * dangerous thing a trading interface can show: it looks live whether the
 * feed died a second ago or a day ago. So every value that can go stale is
 * paired with one of these, and the view renders the age next to the number.
 *
 * Staleness is judged against the cadence that is supposed to refresh the
 * value, not against a fixed threshold — a daily calendar import is healthy at
 * an hour old, while a price snapshot is not.
 */
final readonly class DataAge
{
    public const FRESH = 'FRESH';
    public const STALE = 'STALE';
    public const DEAD  = 'DEAD';
    public const NONE  = 'NONE';

    private function __construct(
        public ?DateTimeImmutable $at,
        public ?int $seconds,
        public string $status,
        public string $label
    ) {
    }

    /**
     * @param int $expectedSeconds How often this value is supposed to refresh.
     */
    public static function since(?DateTimeImmutable $at, DateTimeImmutable $now, int $expectedSeconds): self
    {
        if ($at === null) {
            return new self(null, null, self::NONE, 'never');
        }

        // Clamped at zero: a candle's close time can sit marginally in the
        // future relative to a clock that has drifted, and "-3s ago" reads as
        // a bug even when nothing is wrong.
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        // Two cadences of grace before "stale": one missed run is normal on
        // shared hosting, two is a pattern. Ten means it is not coming back.
        $status = match (true) {
            $seconds <= $expectedSeconds * 2  => self::FRESH,
            $seconds <= $expectedSeconds * 10 => self::STALE,
            default                           => self::DEAD,
        };

        return new self($at, $seconds, $status, self::humanise($seconds));
    }

    public function isFresh(): bool
    {
        return $this->status === self::FRESH;
    }

    public function isMissing(): bool
    {
        return $this->status === self::NONE;
    }

    /** ISO-8601 for the `datetime` attribute, so the markup stays machine-readable. */
    public function iso(): ?string
    {
        return $this->at?->format(DATE_ATOM);
    }

    /**
     * Deliberately coarse. "4 minutes ago" is the operational question; the
     * exact second is noise, and a precise figure invites reading it as live.
     */
    private static function humanise(int $seconds): string
    {
        return match (true) {
            $seconds < 10      => 'just now',
            $seconds < 60      => $seconds . 's ago',
            $seconds < 3600    => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400   => intdiv($seconds, 3600) . 'h ago',
            default            => intdiv($seconds, 86400) . 'd ago',
        };
    }

    /** @return array{at:string|null,seconds:int|null,status:string,label:string} */
    public function toArray(): array
    {
        return [
            'at'      => $this->iso(),
            'seconds' => $this->seconds,
            'status'  => $this->status,
            'label'   => $this->label,
        ];
    }
}
