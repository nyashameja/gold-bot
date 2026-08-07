<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use Paragon\Core\Clock\FrozenClock;
use Paragon\Core\Logging\FileLogger;
use Paragon\Core\Logging\LogLevel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileLoggerTest extends TestCase
{
    private string $directory;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/goldbot-logs-' . bin2hex(random_bytes(6));
        $this->clock = new FrozenClock('2026-08-06 10:30:00');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function logger(LogLevel $minimum = LogLevel::Debug): FileLogger
    {
        return new FileLogger($this->directory, $this->clock, $minimum, 'app');
    }

    /** @return list<array<string,mixed>> */
    private function records(): array
    {
        $file = sprintf('%s/goldbot-%s.log', $this->directory, $this->clock->now()->format('Y-m-d'));

        if (!is_file($file)) {
            return [];
        }

        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($file)));

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values($lines)
        );
    }

    public function test_it_writes_one_json_record_per_line(): void
    {
        $logger = $this->logger();
        $logger->info('First');
        $logger->warning('Second');

        $records = $this->records();

        self::assertCount(2, $records);
        self::assertSame('First', $records[0]['message']);
        self::assertSame('info', $records[0]['level']);
        self::assertSame('warning', $records[1]['level']);
    }

    public function test_records_below_the_minimum_level_are_discarded(): void
    {
        $logger = $this->logger(LogLevel::Warning);

        $logger->debug('noise');
        $logger->info('noise');
        $logger->error('signal');

        $records = $this->records();

        self::assertCount(1, $records);
        self::assertSame('signal', $records[0]['message']);
    }

    public function test_the_event_name_is_promoted_to_a_top_level_field(): void
    {
        $this->logger()->info('Signal generated', ['event' => 'signal.generated', 'signal_id' => 42]);

        $record = $this->records()[0];

        self::assertSame('signal.generated', $record['event']);
        self::assertSame(42, $record['context']['signal_id']);
        self::assertArrayNotHasKey('event', $record['context'], 'The event must not be duplicated into context.');
    }

    /**
     * An exception thrown while building a provider request can carry the API
     * key in its message or trace. Secrets must never reach the log file
     * (docs/01 §10).
     */
    public function test_secret_looking_keys_are_redacted(): void
    {
        $this->logger()->error('Provider call failed', [
            'api_key'       => 'td-live-abcdef123456',
            'bot_token'     => '7654321:AAH-secret',
            'password'      => 'hunter2',
            'Authorization' => 'Bearer abc',
            'endpoint'      => '/time_series',
        ]);

        $context = $this->records()[0]['context'];

        self::assertSame('[redacted]', $context['api_key']);
        self::assertSame('[redacted]', $context['bot_token']);
        self::assertSame('[redacted]', $context['password']);
        self::assertSame('[redacted]', $context['Authorization']);
        self::assertSame('/time_series', $context['endpoint'], 'Non-secret context must survive.');
    }

    public function test_an_exception_is_normalised_rather_than_serialised_whole(): void
    {
        $this->logger()->critical('Boom', ['exception' => new RuntimeException('the cause')]);

        $exception = $this->records()[0]['context']['exception'];

        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertSame('the cause', $exception['message']);
        self::assertStringContainsString('FileLoggerTest.php', $exception['file']);
    }

    public function test_with_context_stamps_every_subsequent_record(): void
    {
        $logger = $this->logger()->withContext(['run_id' => 'abc123']);

        $logger->info('One');
        $logger->info('Two', ['extra' => true]);

        $records = $this->records();

        self::assertSame('abc123', $records[0]['context']['run_id']);
        self::assertSame('abc123', $records[1]['context']['run_id']);
        self::assertTrue($records[1]['context']['extra']);
    }

    public function test_with_context_does_not_mutate_the_original_logger(): void
    {
        $logger = $this->logger();
        $logger->withContext(['run_id' => 'abc123']);

        $logger->info('Unstamped');

        self::assertArrayNotHasKey('run_id', $this->records()[0]['context']);
    }

    public function test_files_are_rotated_by_date(): void
    {
        $logger = $this->logger();
        $logger->info('Today');

        $this->clock->advance('P1D');
        $logger->info('Tomorrow');

        self::assertFileExists($this->directory . '/goldbot-2026-08-06.log');
        self::assertFileExists($this->directory . '/goldbot-2026-08-07.log');
    }

    /**
     * An unbounded log directory eventually exhausts the cPanel disk quota,
     * which takes the whole site down — not just logging.
     */
    public function test_prune_deletes_files_older_than_the_retention_window(): void
    {
        $logger = new FileLogger($this->directory, $this->clock, LogLevel::Debug, 'app', 30);
        $logger->info('current');

        $old = $this->directory . '/goldbot-2020-01-01.log';
        file_put_contents($old, '{}');
        touch($old, $this->clock->timestamp() - (60 * 86400));

        self::assertSame(1, $logger->prune());
        self::assertFileDoesNotExist($old);
        self::assertFileExists($this->directory . '/goldbot-2026-08-06.log');
    }
}
