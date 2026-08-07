<?php

declare(strict_types=1);

namespace GoldBot\Services\Health;

use DateTimeImmutable;
use GoldBot\Domain\Health\HealthReport;
use GoldBot\Domain\Health\HealthStatus;
use GoldBot\Domain\Notification\MessageType;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Services\Telegram\TelegramService;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Runs the checks, records them, and alerts on TRANSITIONS.
 *
 * Alerting on state rather than on condition is the whole design. A component
 * that is critical for six hours is one alert, not three hundred and sixty —
 * and the difference is not politeness, it is whether anyone still reads them.
 * An operator who has muted the channel is worse off than one who was never
 * alerted, because now they believe they would be told.
 *
 * Recovery is announced too. Being told something broke and never told it came
 * back leaves an operator checking manually, which is the state the alerting
 * existed to remove.
 *
 * Alerts go on the ALERT audience, which drains ahead of signals (ADR-07):
 * a warning that the queue has stopped must not be stuck behind the queue it
 * is reporting on.
 */
final class HealthMonitor
{
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly OperationsRepositoryInterface $operations,
        private readonly TelegramService $telegram,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     overall:HealthStatus,
     *     reports:list<HealthReport>,
     *     transitions:list<array<string,mixed>>,
     *     alerts:int
     * }
     */
    public function run(bool $alert = true): array
    {
        $now = $this->clock->now();
        $reports = $this->checker->run();
        $previous = $this->previousStatuses();

        $transitions = [];

        foreach ($reports as $report) {
            $this->record($report, $now);

            $before = $previous[$report->component] ?? null;

            // First observation is not a transition. Announcing every component
            // on first boot would send ten messages nobody asked for and teach
            // the reader that these can be skipped.
            if ($before === null || $before === $report->status) {
                continue;
            }

            $transitions[] = [
                'component' => $report->component,
                'from'      => $before->value,
                'to'        => $report->status->value,
                'message'   => $report->message,
            ];

            $this->logger->warning('Health transition', [
                'event'     => 'health.transition',
                'component' => $report->component,
                'from'      => $before->value,
                'to'        => $report->status->value,
                'message'   => $report->message,
            ]);
        }

        $alerts = $alert ? $this->dispatch($transitions, $reports, $now) : 0;

        return [
            'overall'     => $this->checker->overall($reports),
            'reports'     => $reports,
            'transitions' => $transitions,
            'alerts'      => $alerts,
        ];
    }

    /**
     * The last recorded status per component.
     *
     * @return array<string,HealthStatus>
     */
    private function previousStatuses(): array
    {
        $statuses = [];

        foreach ($this->operations->latestHealthChecks() as $row) {
            $status = HealthStatus::tryFrom((string) $row['status']);

            if ($status !== null) {
                $statuses[(string) $row['component']] = $status;
            }
        }

        return $statuses;
    }

    private function record(HealthReport $report, DateTimeImmutable $at): void
    {
        $this->operations->recordHealthCheck(
            $report->component,
            $report->status->value,
            $report->message,
            $report->metrics === [] ? null : $report->metrics,
            $report->durationMs,
            $at
        );
    }

    /**
     * Enqueue one message per transition.
     *
     * A failure to alert must not fail the health run: the results are already
     * recorded and the page will show them. Reporting the whole check as
     * failed because a message could not be queued would hide the health
     * information behind the alerting problem.
     *
     * @param list<array<string,mixed>> $transitions
     * @param list<HealthReport>        $reports
     */
    private function dispatch(array $transitions, array $reports, DateTimeImmutable $at): int
    {
        if ($transitions === []) {
            return 0;
        }

        $byComponent = [];

        foreach ($reports as $report) {
            $byComponent[$report->component] = $report;
        }

        $queued = 0;

        foreach ($transitions as $transition) {
            $report = $byComponent[$transition['component']] ?? null;

            if ($report === null) {
                continue;
            }

            try {
                $queued += $this->telegram->enqueue(
                    $this->messageTypeFor($report),
                    // Keyed by the transition itself, so a retried run cannot
                    // send the same alert twice — and a component that flaps
                    // back and forth still gets one message per change.
                    sprintf(
                        'health:%s:%s:%s',
                        $report->component,
                        $transition['to'],
                        $at->format('Y-m-d\TH:i')
                    ),
                    $this->payload($report, (string) $transition['from'])
                );
            } catch (Throwable $e) {
                $this->logger->error('Health alert could not be queued', [
                    'event'     => 'health.alert_failed',
                    'component' => $report->component,
                    'exception' => $e,
                ]);
            }
        }

        return $queued;
    }

    /**
     * A provider outage is its own message type, because the operator response
     * differs: an API failure means data is going stale, which is a different
     * problem from the application itself misbehaving.
     */
    private function messageTypeFor(HealthReport $report): MessageType
    {
        return $report->component === 'api_providers'
            ? MessageType::ApiFailure
            : MessageType::SystemError;
    }

    /** @return array<string,mixed> */
    private function payload(HealthReport $report, string $from): array
    {
        $recovered = $report->status === HealthStatus::Ok;

        return [
            'icon'      => $recovered ? '✅' : ($report->status === HealthStatus::Critical ? '🚨' : '⚠️'),
            'severity'  => $recovered ? 'Recovered' : $report->status->label(),
            'component' => $report->label,
            'message'   => $recovered
                ? sprintf('Back to normal (was %s). %s', strtolower($from), $report->message)
                : $report->message,
        ];
    }
}
