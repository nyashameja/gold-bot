<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Domain\Strategy\PillarScore;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\SignalTarget;
use GoldBot\Infrastructure\Clock\FrozenClock;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use GoldBot\Services\Signals\SignalLifecycleService;
use GoldBot\Services\Signals\SignalPublisher;
use GoldBot\Services\Telegram\MessageRenderer;
use GoldBot\Services\Telegram\SignalMessagePayload;
use GoldBot\Services\Telegram\TelegramService;

/**
 * Lifecycle tracking driven by a scripted price path (docs/01 §7).
 *
 * Tracking walks candles rather than sampling the live quote: a quote is a
 * point sample, and between two samples price can travel through a stop and
 * back unnoticed. Because the input is stored data, these tests script the
 * exact path and assert the exact transitions.
 */
final class SignalLifecycleTrackingTest extends IntegrationTestCase
{
    private const CHAT = '-100777';

    private int $instrumentId;

    private Timeframe $m15;

    private SignalRepositoryInterface $signals;

    private CandleRepositoryInterface $candles;

    private FrozenClock $clock;

    private FakeTelegramClient $client;

    private SignalLifecycleService $lifecycle;

    private SignalPublisher $publisher;

    private int $strategyId;

    private int $configId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('telegram_messages')) {
            self::markTestSkipped('Telegram schema not migrated.');
        }

        $container = $this->app->container();
        $this->signals = $container->get(SignalRepositoryInterface::class);
        $this->candles = $container->get(CandleRepositoryInterface::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);
        $instrument = $reference->instrumentBySymbol('XAU/USD');
        $m15 = $reference->timeframeByCode('M15');

        self::assertNotNull($instrument);
        self::assertNotNull($m15);

        $this->instrumentId = $instrument['id'];
        $this->m15 = $m15;

        /** @var StrategyRepositoryInterface $strategies */
        $strategies = $container->get(StrategyRepositoryInterface::class);
        $strategy = $strategies->findByCode('EMA_CROSS');
        self::assertNotNull($strategy);
        $this->strategyId = $strategy['id'];

        $config = $strategies->activeConfig($this->strategyId);
        self::assertNotNull($config);
        $this->configId = $config->id;

        $this->clear();

        // Park any chat this deployment already has, so the message counts
        // below measure the outbox rather than however many channels happen
        // to be configured. See the same guard in TelegramOutboxTest.
        $this->db->run('UPDATE telegram_chats SET is_active = 0 WHERE chat_id <> ?', [self::CHAT]);

        $this->db->insert('telegram_chats', [
            'chat_id'          => self::CHAT,
            'type'             => 'channel',
            'is_active'        => 1,
            'receives_signals' => 1,
        ]);

        /** @var SettingsRepositoryInterface $settings */
        $settings = $container->get(SettingsRepositoryInterface::class);
        $settings->set('telegram.enabled', true);
        $settings->set('signals.breakeven_after_tp1', true);

        $this->clock = new FrozenClock('2026-03-02 20:00:00');
        $this->client = new FakeTelegramClient();

        $telegram = new TelegramService(
            $container->get(TelegramRepositoryInterface::class),
            $this->client,
            new MessageRenderer($this->db),
            $settings,
            $this->clock,
            $container->get(LoggerInterface::class)
        );

        $this->publisher = new SignalPublisher(
            $this->db,
            $this->signals,
            $telegram,
            new SignalMessagePayload(),
            $this->clock,
            $container->get(LoggerInterface::class)
        );

        $this->lifecycle = new SignalLifecycleService(
            $this->signals,
            $this->candles,
            $this->publisher,
            $settings,
            $this->clock,
            $container->get(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $this->clear();

        $this->app->container()->get(SettingsRepositoryInterface::class)->set('telegram.enabled', false);

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM telegram_messages');
        $this->db->run('DELETE FROM telegram_chats WHERE chat_id = ?', [self::CHAT]);
        $this->db->run('UPDATE telegram_chats SET is_active = 1 WHERE chat_id <> ?', [self::CHAT]);
        $this->db->run('DELETE FROM signals');
        $this->db->run('DELETE FROM candles WHERE instrument_id = ?', [$this->instrumentId]);
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    /**
     * Create a long: entry 3300, stop 3290 (10 of risk), TP1 3310, TP2 3320,
     * TP3 3330 — so 1R, 2R and 3R land on round numbers.
     */
    private function createSignal(): int
    {
        $result = SignalResult::signal(
            Direction::Buy,
            75.0,
            [new PillarScore('TREND', 100.0, 100.0)],
            3300.0,
            3290.0,
            [
                new SignalTarget(1, 3310.0, 50.0, 1.0),
                new SignalTarget(2, 3320.0, 30.0, 2.0),
                new SignalTarget(3, 3330.0, 20.0, 3.0),
            ]
        );

        return $this->signals->create(
            $result,
            $this->strategyId,
            $this->configId,
            null,
            $this->instrumentId,
            $this->m15->id,
            $this->utc('2026-03-02 20:00:00'),
            $this->utc('2026-03-03 00:00:00'),
            'NEW_YORK',
            'UPTREND'
        );
    }

    /**
     * Seed candles as (high, low) pairs starting from the signal's generation.
     *
     * @param list<array{0:float,1:float}> $bars
     */
    private function seedPath(array $bars): void
    {
        $start = $this->utc('2026-03-02 20:00:00');
        $candles = [];

        foreach ($bars as $i => [$high, $low]) {
            $openTime = $start->modify(sprintf('+%d minutes', ($i + 1) * 15));
            $mid = ($high + $low) / 2;

            $candles[] = new Candle(
                $openTime,
                $openTime->modify('+14 minutes 59 seconds'),
                number_format($mid, 5, '.', ''),
                number_format($high, 5, '.', ''),
                number_format($low, 5, '.', ''),
                number_format($mid, 5, '.', ''),
                '1000',
                true
            );
        }

        $this->candles->upsertSeries($this->instrumentId, $this->m15->id, new CandleSeries($candles), 'TEST');
        $this->clock->setTo($start->modify(sprintf('+%d minutes', (count($bars) + 2) * 15)));
    }

    /** @return list<string> */
    private function eventTypes(int $signalId): array
    {
        return array_map(
            static fn (array $e): string => (string) $e['event_type'],
            $this->signals->events($signalId)
        );
    }

    /** @return list<string> */
    private function queuedTemplates(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['template_code'],
            $this->db->select('SELECT template_code FROM telegram_messages ORDER BY id')
        );
    }

    // ── Entry ────────────────────────────────────────────────────────────────

    public function test_entry_fills_when_a_candle_covers_the_entry_price(): void
    {
        $signalId = $this->createSignal();

        // Trades through 3300.
        $this->seedPath([[3305, 3298]]);

        $result = $this->lifecycle->track();

        self::assertSame(1, $result['activated']);
        self::assertSame(SignalState::Active->value, $this->signals->find($signalId)['state']);
        self::assertContains(SignalEventType::EntryActivated->value, $this->eventTypes($signalId));
    }

    public function test_a_signal_stays_pending_while_price_never_reaches_entry(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([[3320, 3312], [3325, 3315]]);

        $result = $this->lifecycle->track();

        self::assertSame(0, $result['activated']);
        self::assertSame(SignalState::Pending->value, $this->signals->find($signalId)['state']);
    }

    // ── Targets and stops ────────────────────────────────────────────────────

    /**
     * The full path: fill, TP1 (which moves the stop to breakeven), then TP2.
     */
    public function test_targets_are_hit_in_order_and_tp1_moves_the_stop_to_breakeven(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([
            [3305, 3298],  // fills at 3300
            [3312, 3303],  // TP1 at 3310
            [3322, 3312],  // TP2 at 3320
        ]);

        $result = $this->lifecycle->track();

        self::assertSame(1, $result['activated']);
        self::assertSame(2, $result['targets']);

        $events = $this->eventTypes($signalId);

        self::assertContains(SignalEventType::Tp1Hit->value, $events);
        self::assertContains(SignalEventType::MovedToBreakeven->value, $events);
        self::assertContains(SignalEventType::Tp2Hit->value, $events);
        self::assertNotContains(SignalEventType::Tp3Hit->value, $events);

        // TP1 and TP2 are partial: the signal is still open.
        self::assertSame(SignalState::Breakeven->value, $this->signals->find($signalId)['state']);

        $targets = $this->signals->targets($signalId);
        self::assertNotNull($targets[0]['hit_at']);
        self::assertNotNull($targets[1]['hit_at']);
        self::assertNull($targets[2]['hit_at']);
    }

    public function test_the_final_target_closes_the_signal_as_a_win(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([
            [3305, 3298],
            [3312, 3303],
            [3322, 3312],
            [3332, 3322],  // TP3
        ]);

        $this->lifecycle->track();

        $signal = $this->signals->find($signalId);

        self::assertSame(SignalState::ClosedWin->value, $signal['state']);
        self::assertNotNull($signal['closed_at']);
        // Exit 3330, entry 3300, risk 10 → +3R.
        self::assertEqualsWithDelta(3.0, (float) $signal['realised_r'], 0.001);
    }

    public function test_a_stop_closes_the_signal_at_a_loss(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([
            [3305, 3298],  // fills
            [3302, 3288],  // through the stop at 3290
        ]);

        $result = $this->lifecycle->track();

        self::assertSame(1, $result['stopped']);

        $signal = $this->signals->find($signalId);
        self::assertSame(SignalState::ClosedLoss->value, $signal['state']);
        self::assertEqualsWithDelta(-1.0, (float) $signal['realised_r'], 0.001);
    }

    /**
     * The unavoidable ambiguity, resolved pessimistically. When one candle
     * spans both stop and target the order is unknowable; assuming the target
     * came first would report a win rate the live account never reproduces.
     */
    public function test_a_candle_spanning_both_stop_and_target_is_treated_as_a_stop(): void
    {
        $signalId = $this->createSignal();

        // One bar covering entry, TP1 and the stop.
        $this->seedPath([[3315, 3285]]);

        $this->lifecycle->track();

        $signal = $this->signals->find($signalId);

        self::assertSame(SignalState::ClosedLoss->value, $signal['state']);
        self::assertNotContains(SignalEventType::Tp1Hit->value, $this->eventTypes($signalId));
    }

    // ── Expiry ───────────────────────────────────────────────────────────────

    public function test_an_unfilled_signal_expires(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([[3320, 3315]]);
        $this->clock->setTo($this->utc('2026-03-03 01:00:00'));

        $result = $this->lifecycle->track();

        self::assertSame(1, $result['expired']);
        self::assertSame(SignalState::Expired->value, $this->signals->find($signalId)['state']);
    }

    /** A signal that filled cannot expire, even past its expiry time. */
    public function test_an_activated_signal_does_not_expire(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([[3305, 3298]]);
        $this->clock->setTo($this->utc('2026-03-03 01:00:00'));

        $this->lifecycle->track();

        self::assertSame(SignalState::Active->value, $this->signals->find($signalId)['state']);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    /**
     * Tracking resumes from the last recorded event, so a candle is never
     * evaluated twice — a restart must not re-fire a transition.
     */
    public function test_running_the_tracker_twice_produces_no_duplicate_events(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([
            [3305, 3298],
            [3312, 3303],
        ]);

        $this->lifecycle->track();
        $eventsAfterFirst = $this->eventTypes($signalId);
        $messagesAfterFirst = $this->queuedTemplates();

        $this->lifecycle->track();

        self::assertSame($eventsAfterFirst, $this->eventTypes($signalId));
        self::assertSame($messagesAfterFirst, $this->queuedTemplates());
    }

    // ── Notifications ────────────────────────────────────────────────────────

    /**
     * Exactly one message per transition — no more, and none missing.
     */
    public function test_each_transition_queues_exactly_one_message(): void
    {
        $signalId = $this->createSignal();

        $this->seedPath([
            [3305, 3298],
            [3312, 3303],
            [3322, 3312],
            [3332, 3322],
        ]);

        $this->lifecycle->track();

        $templates = $this->queuedTemplates();

        self::assertContains('signal.entry_activated', $templates);
        self::assertContains('signal.tp1', $templates);
        self::assertContains('signal.breakeven', $templates);
        self::assertContains('signal.tp2', $templates);
        self::assertContains('signal.tp3', $templates);

        self::assertSame(count($templates), count(array_unique($templates)), 'No duplicates.');
    }

    public function test_a_published_signal_queues_its_new_signal_message_atomically(): void
    {
        $result = SignalResult::signal(
            Direction::Buy,
            80.0,
            [new PillarScore('TREND', 100.0, 100.0)],
            3300.0,
            3290.0,
            [new SignalTarget(1, 3320.0, 100.0, 2.0)]
        );

        $context = new \GoldBot\Domain\Strategy\StrategyContext(
            instrumentId: $this->instrumentId,
            timeframe:    $this->m15,
            at:           $this->utc('2026-03-02 20:00:00'),
            series:       [],
            indicators:   [],
            trends:       []
        );

        $signalId = $this->publisher->publish(
            $result,
            ['id' => $this->strategyId, 'code' => 'EMA_CROSS', 'name' => 'EMA Trend', 'class_name' => ''],
            $this->app->container()->get(StrategyRepositoryInterface::class)->configById($this->configId),
            $context,
            0,
            $this->m15->id,
            null
        );

        self::assertGreaterThan(0, $signalId);
        self::assertSame(['signal.new'], $this->queuedTemplates());

        $text = (string) $this->db->scalar('SELECT rendered_text FROM telegram_messages LIMIT 1');
        self::assertStringContainsString('BUY', $text);
        self::assertStringContainsString('3,300.00', $text);
    }

    /** An illegal transition writes nothing — not an event, not a message. */
    public function test_a_refused_transition_queues_no_message(): void
    {
        $signalId = $this->createSignal();

        // A stop cannot fire on a signal that never activated.
        self::assertFalse(
            $this->publisher->recordTransition($signalId, SignalEventType::StopLossHit, $this->clock->now(), 3290.0)
        );

        self::assertSame([], $this->queuedTemplates());
        self::assertSame([SignalEventType::Generated->value], $this->eventTypes($signalId));
    }
}
