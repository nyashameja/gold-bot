<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Domain\Health\HealthStatus;
use GoldBot\Infrastructure\Clock\FrozenClock;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use GoldBot\Services\Health\HealthChecker;
use GoldBot\Services\Health\HealthMonitor;
use GoldBot\Services\Telegram\MessageRenderer;
use GoldBot\Services\Telegram\TelegramService;
use GoldBot\Infrastructure\Logging\LoggerInterface;

/**
 * Health checks, transitions and alerting.
 *
 * The property under test is that alerts fire on CHANGE, not on condition. A
 * component that is critical for six hours must produce one alert, not three
 * hundred and sixty — and the difference is not politeness, it is whether
 * anyone still reads the channel. An operator who has muted it is worse off
 * than one who was never alerted, because now they believe they would be told.
 */
final class HealthMonitorTest extends IntegrationTestCase
{
    private const CHAT = '-100777555';

    private HealthMonitor $monitor;

    private HealthChecker $checker;

    private FrozenClock $clock;

    private SettingsRepositoryInterface $settings;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('health_checks')) {
            self::markTestSkipped('Operations schema not migrated.');
        }

        $container = $this->app->container();
        $this->settings = $container->get(SettingsRepositoryInterface::class);
        $this->clock = new FrozenClock('2026-06-20 12:00:00');

        $this->clear();

        // Park any real chat, so the counts below measure the outbox rather
        // than however many channels happen to be configured.
        $this->db->run('UPDATE telegram_chats SET is_active = 0 WHERE chat_id <> ?', [self::CHAT]);

        $this->db->insert('telegram_chats', [
            'chat_id'          => self::CHAT,
            'type'             => 'channel',
            'is_active'        => 1,
            'receives_signals' => 0,
            'receives_alerts'  => 1,
        ]);

        $this->settings->set('telegram.enabled', true);

        // Built with the FROZEN clock rather than resolved from the container,
        // which would give the real one — and then a task marked "last
        // succeeded just now" by this test would read as weeks overdue.
        $this->checker = new HealthChecker(
            $this->db,
            $container->get(OperationsRepositoryInterface::class),
            $container->get(MarketReferenceRepositoryInterface::class),
            $container->get(CandleRepositoryInterface::class),
            $container->get(PriceSnapshotRepositoryInterface::class),
            $container->get(TelegramRepositoryInterface::class),
            $container->get(TelegramClientInterface::class),
            $this->clock,
            $this->app->basePath('storage'),
            $this->app->basePath('storage/logs')
        );

        $this->monitor = new HealthMonitor(
            $this->checker,
            $container->get(OperationsRepositoryInterface::class),
            new TelegramService(
                $container->get(TelegramRepositoryInterface::class),
                new FakeTelegramClient(),
                new MessageRenderer($this->db),
                $this->settings,
                $this->clock,
                $container->get(LoggerInterface::class)
            ),
            $this->clock,
            $container->get(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $this->clear();
        $this->settings->set('telegram.enabled', false);

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM health_checks');
        $this->db->run('DELETE FROM telegram_messages');
        $this->db->run('DELETE FROM telegram_chats WHERE chat_id = ?', [self::CHAT]);
        $this->db->run('UPDATE telegram_chats SET is_active = 1 WHERE chat_id <> ?', [self::CHAT]);
    }

    private function alertCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages');
    }

    /** Force a component's last recorded status, to stage a transition. */
    private function seedStatus(string $component, HealthStatus $status): void
    {
        $this->db->insert('health_checks', [
            'component'  => $component,
            'status'     => $status->value,
            'message'    => 'Seeded for the test.',
            'checked_at' => $this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function statusOf(array $result, string $component): HealthStatus
    {
        foreach ($result['reports'] as $report) {
            if ($report->component === $component) {
                return $report->status;
            }
        }

        self::fail("No report for {$component}.");
    }

    // ── The checks themselves ────────────────────────────────────────────────

    public function test_every_documented_component_is_checked(): void
    {
        $reports = $this->checker->run();
        $components = array_map(static fn ($r): string => $r->component, $reports);

        // docs/01 §11 lists these; a missing one is a component nobody is
        // watching, which is exactly how an outage goes unnoticed.
        self::assertSame(HealthChecker::COMPONENTS, $components);
    }

    public function test_a_broken_check_does_not_take_down_the_others(): void
    {
        // Every check runs even though this environment has several degraded
        // components; none throws out of run().
        $reports = $this->checker->run();

        self::assertCount(count(HealthChecker::COMPONENTS), $reports);

        foreach ($reports as $report) {
            self::assertNotSame('', $report->message, $report->component . ' must say something.');
            self::assertNotNull($report->durationMs);
        }
    }

    /**
     * The staleness detector — the check that catches the failure mode that
     * actually hurts. A cron that stopped running produces no errors at all
     * (docs/01 §11).
     */
    public function test_a_task_that_stopped_running_is_reported_as_overdue(): void
    {
        // A task whose last success is well beyond its own cadence.
        $this->db->run(
            "UPDATE scheduled_tasks
             SET is_enabled = 1, last_success_at = ?, consecutive_failures = 0
             WHERE code = 'market.price'",
            [$this->clock->now()->modify('-3 hours')->format('Y-m-d H:i:s')]
        );

        $reports = $this->checker->run();
        $scheduler = null;

        foreach ($reports as $report) {
            if ($report->component === 'scheduler') {
                $scheduler = $report;
            }
        }

        self::assertNotNull($scheduler);
        self::assertSame(HealthStatus::Critical, $scheduler->status);
        self::assertStringContainsString('market.price', $scheduler->message);
        self::assertContains('market.price', array_map(
            static fn (string $entry): string => explode(' ', $entry)[0],
            $scheduler->metrics['overdue']
        ));
    }

    /**
     * Disabling a task raises a warning — not a critical.
     *
     * Two failure modes pull in opposite directions here. Treating a disabled
     * task as overdue would page an operator for something they did on
     * purpose, and an alert that fires for deliberate acts gets muted. But
     * ignoring it entirely means a "temporary" disable that was forgotten
     * leaves the platform quietly not working with nothing complaining — which
     * is the exact failure this check exists to catch. So it is visible, named,
     * and not loud.
     */
    public function test_disabling_a_task_raises_a_warning_naming_it(): void
    {
        // Bring everything up to date first, so the only finding is the
        // disabled task rather than a pile of pre-existing overdue ones.
        $this->db->run(
            'UPDATE scheduled_tasks SET last_success_at = ?, consecutive_failures = 0',
            [$this->clock->now()->format('Y-m-d H:i:s')]
        );
        $this->db->run("UPDATE scheduled_tasks SET is_enabled = 0 WHERE code = 'market.price'");

        try {
            $scheduler = null;

            foreach ($this->checker->run() as $report) {
                if ($report->component === 'scheduler') {
                    $scheduler = $report;
                }
            }

            self::assertNotNull($scheduler);
            self::assertSame(HealthStatus::Warning, $scheduler->status);
            self::assertStringContainsString('market.price', $scheduler->message);
            self::assertContains('market.price', $scheduler->metrics['disabled']);

            // Warning, not critical: the operator chose this.
            self::assertNotSame(HealthStatus::Critical, $scheduler->status);

            // And it is NOT counted as overdue — that word is reserved for a
            // task that should be running and is not.
            self::assertStringNotContainsString('market.price', implode(' ', $scheduler->metrics['overdue']));
        } finally {
            $this->db->run("UPDATE scheduled_tasks SET is_enabled = 1 WHERE code = 'market.price'");
        }
    }

    // ── Transitions ──────────────────────────────────────────────────────────

    /**
     * The first observation is not a transition. Announcing every component on
     * first boot would send ten messages nobody asked for and teach the reader
     * that these can be skipped.
     */
    public function test_the_first_run_records_but_does_not_alert(): void
    {
        $result = $this->monitor->run();

        self::assertSame([], $result['transitions']);
        self::assertSame(0, $result['alerts']);
        self::assertSame(0, $this->alertCount());

        // Recorded, though — that is what makes the next run able to compare.
        self::assertSame(
            count(HealthChecker::COMPONENTS),
            (int) $this->db->scalar('SELECT COUNT(*) FROM health_checks')
        );
    }

    /**
     * A component that stays broken alerts ONCE.
     */
    public function test_a_persistently_broken_component_alerts_only_on_the_change(): void
    {
        // Establish a healthy baseline for whatever the environment reports.
        $first = $this->monitor->run();
        $observed = $this->statusOf($first, 'error_rate');

        // Stage the opposite of what is currently true, so the next run is a
        // genuine transition regardless of the environment.
        $this->seedStatus('error_rate', $observed === HealthStatus::Ok ? HealthStatus::Critical : HealthStatus::Ok);

        $second = $this->monitor->run();
        $components = array_column($second['transitions'], 'component');

        self::assertContains('error_rate', $components);
        $afterTransition = $this->alertCount();
        self::assertGreaterThan(0, $afterTransition);

        // Nothing changed this time, so nothing new is sent.
        $third = $this->monitor->run();

        self::assertSame([], array_values(array_filter(
            $third['transitions'],
            static fn (array $t): bool => $t['component'] === 'error_rate'
        )));
        self::assertSame($afterTransition, $this->alertCount(), 'A steady state must not re-alert.');
    }

    /**
     * Recovery is announced. Being told something broke and never told it came
     * back leaves an operator checking by hand, which is the state the
     * alerting existed to remove.
     */
    public function test_recovery_is_announced(): void
    {
        $baseline = $this->monitor->run();
        $observed = $this->statusOf($baseline, 'database');

        // The database check is OK in a working test environment; stage it as
        // previously critical so this run is a recovery.
        self::assertSame(HealthStatus::Ok, $observed, 'The test database should be healthy.');

        $this->db->run('DELETE FROM health_checks');
        $this->seedStatus('database', HealthStatus::Critical);

        $result = $this->monitor->run();

        $transition = null;

        foreach ($result['transitions'] as $candidate) {
            if ($candidate['component'] === 'database') {
                $transition = $candidate;
            }
        }

        self::assertNotNull($transition);
        self::assertSame('CRITICAL', $transition['from']);
        self::assertSame('OK', $transition['to']);

        $body = (string) $this->db->scalar(
            'SELECT rendered_text FROM telegram_messages ORDER BY id DESC LIMIT 1'
        );

        self::assertStringContainsString('Recovered', $body);
    }

    /**
     * Alerts go on the alert audience, which drains ahead of signals (ADR-07).
     * A warning that the queue has stopped must not be stuck behind the queue
     * it is reporting on.
     */
    public function test_alerts_use_the_priority_lane(): void
    {
        $this->monitor->run();
        $this->seedStatus('storage', HealthStatus::Critical);
        $this->monitor->run();

        $row = $this->db->selectOne(
            'SELECT template_code, priority FROM telegram_messages ORDER BY id DESC LIMIT 1'
        );

        self::assertNotNull($row);
        // System alerts are priority 1; a new signal is 4.
        self::assertSame(1, (int) $row['priority']);
        self::assertStringStartsWith('system.', (string) $row['template_code']);
    }

    /**
     * The same transition observed twice in the same minute is one message.
     * Without this, a retried task run would double-send.
     */
    public function test_an_alert_is_idempotent_within_its_window(): void
    {
        $this->monitor->run();
        $this->seedStatus('logs', HealthStatus::Critical);

        $this->monitor->run();
        $after = $this->alertCount();

        // Re-stage and re-run at the same frozen instant.
        $this->seedStatus('logs', HealthStatus::Critical);
        $this->monitor->run();

        self::assertSame($after, $this->alertCount());
    }

    public function test_results_are_persisted_for_history(): void
    {
        $this->monitor->run();

        $stored = $this->db->select(
            'SELECT component, status, message, metrics, duration_ms FROM health_checks'
        );

        self::assertCount(count(HealthChecker::COMPONENTS), $stored);

        foreach ($stored as $row) {
            self::assertNotNull($row['status']);
            self::assertNotNull($row['duration_ms'], 'Duration is what shows a check getting slower.');
        }
    }
}
