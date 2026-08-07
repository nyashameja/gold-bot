<?php

declare(strict_types=1);

namespace GoldBot\Domain\Health;

/**
 * The state of one component (docs/01 §11).
 *
 * Four levels rather than a boolean, because the operational responses differ:
 * a warning is something to look at this week, a critical is something to look
 * at now, and "unknown" is neither — it means the check could not run, which
 * must not be reported as health.
 */
enum HealthStatus: string
{
    case Ok       = 'OK';
    case Warning  = 'WARNING';
    case Critical = 'CRITICAL';
    /** The check itself could not answer. Never counted as healthy. */
    case Unknown  = 'UNKNOWN';

    /**
     * Ordering for "worst wins".
     *
     * Unknown sits above Ok deliberately: a component whose health cannot be
     * determined is not a component that is fine.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok       => 0,
            self::Unknown  => 1,
            self::Warning  => 2,
            self::Critical => 3,
        };
    }

    public function isDegraded(): bool
    {
        return $this !== self::Ok;
    }

    /**
     * The worst of a set.
     *
     * An overall status that averaged away one critical component would be
     * worse than no summary at all — it would actively hide the thing the
     * page exists to surface.
     *
     * @param list<self> $statuses
     */
    public static function worst(array $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
