<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Domain\Notification\MessageType;
use GoldBot\Infrastructure\Clock\FrozenClock;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use GoldBot\Services\Telegram\MessageRenderer;
use GoldBot\Services\Telegram\TelegramService;
use RuntimeException;

/**
 * The transactional outbox (ADR-07).
 *
 * Delivery over an HTTP API cannot be exactly-once, so the design target is
 * at-least-once with dedupe. These tests pin the properties that make that
 * true: idempotency, backoff, dead-lettering, and — the reason the pattern
 * exists — atomicity with the write that caused the message.
 */
final class TelegramOutboxTest extends IntegrationTestCase
{
    private const CHAT = '-100999';

    private FakeTelegramClient $client;

    private FrozenClock $clock;

    private TelegramService $telegram;

    private TelegramRepositoryInterface $repository;

    private SettingsRepositoryInterface $settings;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('telegram_messages')) {
            self::markTestSkipped('Telegram schema not migrated.');
        }

        $container = $this->app->container();
        $this->repository = $container->get(TelegramRepositoryInterface::class);
        $this->settings = $container->get(SettingsRepositoryInterface::class);

        $this->clear();

        // Any chat this deployment already has is parked for the duration of
        // the test. Without this the suite quietly assumes it is the only chat
        // in the database — which is true on a fresh install and false the
        // moment anyone configures a real channel, and the failure then looks
        // like a broken outbox rather than a broken test.
        $this->db->run('UPDATE telegram_chats SET is_active = 0 WHERE chat_id <> ?', [self::CHAT]);

        $this->db->insert('telegram_chats', [
            'chat_id'            => self::CHAT,
            'type'               => 'channel',
            'title'              => 'Test channel',
            'is_active'          => 1,
            'receives_signals'   => 1,
            'receives_alerts'    => 1,
            'receives_summaries' => 1,
        ]);

        $this->settings->set('telegram.enabled', true);
        $this->settings->set('telegram.max_attempts', 3);
        $this->settings->set('telegram.retry_base_seconds', 30);

        $this->client = new FakeTelegramClient();
        $this->clock = new FrozenClock('2026-08-06 12:00:00');

        $this->telegram = new TelegramService(
            $this->repository,
            $this->client,
            $container->get(MessageRenderer::class),
            $this->settings,
            $this->clock,
            $container->get(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $this->clear();
        $this->settings->set('telegram.enabled', false);
        $this->settings->set('telegram.max_attempts', 5);

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM telegram_messages');
        $this->db->run('DELETE FROM telegram_chats WHERE chat_id = ?', [self::CHAT]);

        // Restore whatever this deployment had configured. Leaving a real
        // channel deactivated after a test run would silently stop delivery.
        $this->db->run('UPDATE telegram_chats SET is_active = 1 WHERE chat_id <> ?', [self::CHAT]);
    }

    /** @return array<string,mixed>|null */
    private function message(): ?array
    {
        return $this->db->selectOne('SELECT * FROM telegram_messages ORDER BY id DESC LIMIT 1');
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    /**
     * The guarantee that matters: the same logical message enqueued twice
     * produces one row and one send, however many times the producer runs.
     */
    public function test_the_same_idempotency_key_enqueues_once(): void
    {
        $first = $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', ['entry' => '3300.00']);
        $second = $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', ['entry' => '3300.00']);

        self::assertSame(1, $first);
        self::assertSame(0, $second, 'A repeat enqueue is a no-op.');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'));
    }

    public function test_different_events_on_the_same_signal_are_distinct_messages(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);
        $this->telegram->enqueue(MessageType::Tp1Hit, 'signal:abc:TP1_HIT', []);

        self::assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'));
    }

    /**
     * Keys are scoped per chat, so adding a second chat later does not suppress
     * its copy on the grounds that the first already went out.
     */
    public function test_each_chat_gets_its_own_copy(): void
    {
        $this->db->insert('telegram_chats', [
            'chat_id'          => '-100888',
            'type'             => 'channel',
            'is_active'        => 1,
            'receives_signals' => 1,
        ]);

        try {
            $queued = $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

            self::assertSame(2, $queued);
            self::assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'));
        } finally {
            $this->db->run('DELETE FROM telegram_chats WHERE chat_id = ?', ['-100888']);
        }
    }

    /** Alerts and signals route by subscription flag, not by broadcast. */
    public function test_audience_routing_respects_subscription_flags(): void
    {
        $this->db->run('UPDATE telegram_chats SET receives_alerts = 0 WHERE chat_id = ?', [self::CHAT]);

        self::assertSame(0, $this->telegram->enqueue(MessageType::SystemError, 'sys:1', ['message' => 'x']));
        self::assertSame(1, $this->telegram->enqueue(MessageType::NewSignal, 'signal:x:GENERATED', []));
    }

    public function test_nothing_is_queued_when_telegram_is_disabled(): void
    {
        $this->settings->set('telegram.enabled', false);

        self::assertSame(0, $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'));
    }

    // ── Draining ─────────────────────────────────────────────────────────────

    public function test_a_queued_message_is_sent_and_marked(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', ['entry' => '3300.00']);

        $this->client->willSucceed('4242');
        $result = $this->telegram->drain();

        self::assertSame(1, $result['sent']);
        self::assertCount(1, $this->client->sent);
        self::assertSame(self::CHAT, $this->client->sent[0]['chatId']);

        $message = $this->message();
        self::assertSame('SENT', $message['status']);
        self::assertSame('4242', $message['provider_message_id']);
        self::assertNotNull($message['sent_at']);
    }

    public function test_a_sent_message_is_not_sent_again(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

        $this->client->willSucceed();
        $this->telegram->drain();
        $this->telegram->drain();

        self::assertCount(1, $this->client->sent, 'Only PENDING messages are claimed.');
    }

    /** Backoff grows so a struggling provider is not hammered. */
    public function test_a_retryable_failure_backs_off_exponentially(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

        $this->client->willFailRetryable();
        $this->telegram->drain();

        $message = $this->message();
        self::assertSame('PENDING', $message['status']);
        self::assertSame(1, (int) $message['attempts']);
        // First retry: base 30s.
        self::assertSame('2026-08-06 12:00:30', $message['available_at']);

        // Not yet due.
        $this->client->willFailRetryable();
        self::assertSame(0, $this->telegram->drain()['failed']);

        $this->clock->advanceSeconds(31);
        $this->client->willFailRetryable();
        $this->telegram->drain();

        // Second retry: 60s from the new now.
        self::assertSame('2026-08-06 12:01:31', $this->message()['available_at']);
    }

    public function test_a_message_is_dead_lettered_after_its_attempts_are_exhausted(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->client->willFailRetryable();
            $this->telegram->drain();
            $this->clock->advanceSeconds(3600);
        }

        $message = $this->message();

        self::assertSame('DEAD', $message['status']);
        self::assertSame(3, (int) $message['attempts']);
        self::assertNotNull($message['last_error']);
    }

    /**
     * A permanent failure is dead-lettered at once. Retrying a malformed
     * message four more times only delays the inevitable and spends quota.
     */
    public function test_a_permanent_failure_is_dead_lettered_immediately(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

        $this->client->willFailPermanently('Bad Request: chat not found');
        $result = $this->telegram->drain();

        self::assertSame(1, $result['dead']);

        $message = $this->message();
        self::assertSame('DEAD', $message['status']);
        self::assertSame(1, (int) $message['attempts'], 'No pointless retries.');
    }

    /**
     * An absent token is a configuration gap, not a message defect — the
     * backlog must survive to deliver once it is filled.
     */
    public function test_an_unconfigured_client_leaves_messages_queued(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);
        $this->client->setConfigured(false);

        $result = $this->telegram->drain();

        self::assertSame(1, $result['skipped']);
        self::assertSame('PENDING', $this->message()['status']);
        self::assertSame(0, (int) $this->message()['attempts']);
    }

    /**
     * A message about a broken queue must not be stuck behind the queue it is
     * reporting on.
     */
    public function test_system_alerts_drain_before_signals(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);
        $this->telegram->enqueue(MessageType::SystemError, 'sys:1', ['message' => 'Provider down']);

        $this->client->willSucceed()->willSucceed();
        $this->telegram->drain();

        self::assertStringContainsString('Provider down', $this->client->sent[0]['text']);
    }

    public function test_a_dead_message_can_be_requeued_by_an_operator(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);

        $this->client->willFailPermanently();
        $this->telegram->drain();

        $id = (int) $this->message()['id'];

        self::assertTrue($this->telegram->requeue($id));
        self::assertSame('PENDING', $this->message()['status']);
        self::assertSame(0, (int) $this->message()['attempts']);
    }

    /** Depth alone does not say whether the queue is moving; age does. */
    public function test_queue_stats_report_depth_and_the_age_of_the_oldest_pending(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', []);
        $this->clock->advanceSeconds(300);

        $stats = $this->telegram->queueStats();

        self::assertSame(1, $stats['pending']);
        self::assertSame(0, $stats['dead']);
        self::assertGreaterThanOrEqual(300, (int) $stats['oldest_pending_seconds']);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function test_the_stored_template_is_rendered_with_the_payload(): void
    {
        $this->telegram->enqueue(MessageType::NewSignal, 'signal:abc:GENERATED', [
            'direction_word' => 'BUY',
            'direction_icon' => '🟢',
            'symbol'         => 'XAU/USD',
            'entry'          => '3,300.00',
            'stop'           => '3,290.00',
            'risk_reward'    => '2.00',
            'score'          => '78.5',
            'strategy'       => 'EMA Trend',
            'session'        => 'LONDON',
            'targets_block'  => "🎯 TP1: 3,315.00\n🎯 TP2: 3,330.00",
        ]);

        $text = (string) $this->message()['rendered_text'];

        self::assertStringContainsString('BUY', $text);
        self::assertStringContainsString('3,300.00', $text);
        self::assertStringContainsString('TP1: 3,315.00', $text, 'Raw blocks keep their formatting.');
        self::assertStringContainsString('EMA Trend', $text);
    }

    /**
     * Telegram's HTML mode rejects the whole message on a stray '<', so a
     * signal that fails to send because of an unescaped character would be a
     * silent outage.
     */
    public function test_payload_values_are_escaped_so_a_stray_character_cannot_break_the_send(): void
    {
        $this->telegram->enqueue(MessageType::SystemError, 'sys:esc', [
            'component' => 'Provider <script>',
            'message'   => 'Rate limit & backoff',
            'severity'  => 'ERROR',
            'icon'      => '🚨',
        ]);

        $text = (string) $this->message()['rendered_text'];

        self::assertStringNotContainsString('<script>', $text);
        self::assertStringContainsString('&lt;script&gt;', $text);
        self::assertStringContainsString('&amp;', $text);
    }

    public function test_a_missing_template_still_produces_a_sendable_message(): void
    {
        $this->db->run('UPDATE telegram_templates SET is_active = 0 WHERE code = ?', [MessageType::NewSignal->value]);

        try {
            // A fresh renderer, so the template cache reflects the change.
            $telegram = new TelegramService(
                $this->repository,
                $this->client,
                new MessageRenderer($this->db),
                $this->settings,
                $this->clock,
                $this->app->container()->get(LoggerInterface::class)
            );

            $telegram->enqueue(MessageType::NewSignal, 'signal:fallback:GENERATED', ['entry' => '3300.00']);

            $text = (string) $this->message()['rendered_text'];

            self::assertNotSame('', $text, 'Losing an alert to a deleted template row would be far worse.');
            self::assertStringContainsString('3300.00', $text);
        } finally {
            $this->db->run('UPDATE telegram_templates SET is_active = 1 WHERE code = ?', [MessageType::NewSignal->value]);
        }
    }

    // ── Atomicity: the reason the outbox exists (ADR-07) ─────────────────────

    /**
     * enqueue() only writes rows, so it joins the caller's transaction. A
     * rolled-back signal must therefore leave no orphaned alert — which is
     * exactly what sending inline could not guarantee.
     */
    public function test_a_rolled_back_transaction_leaves_no_queued_message(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->telegram->enqueue(MessageType::NewSignal, 'signal:rollback:GENERATED', []);

                throw new RuntimeException('the caller failed after enqueuing');
            });

            self::fail('The exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'),
            'An alert for a signal that never existed must not survive.'
        );
    }

    public function test_a_committed_transaction_keeps_its_queued_message(): void
    {
        $this->db->transaction(function (): void {
            $this->telegram->enqueue(MessageType::NewSignal, 'signal:committed:GENERATED', []);
        });

        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM telegram_messages'));
    }

    // ── The real client is the bound implementation ──────────────────────────

    public function test_the_container_binds_the_real_client(): void
    {
        self::assertInstanceOf(
            \GoldBot\Integrations\Telegram\TelegramClient::class,
            $this->app->container()->get(TelegramClientInterface::class)
        );
    }
}
