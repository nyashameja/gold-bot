<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Domain\Notification\MessageType;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Services\Telegram\SignalMessagePayload;
use GoldBot\Services\Telegram\TelegramService;

/**
 * Persists a signal and enqueues its notification atomically (ADR-07).
 *
 * This class exists for one reason: the signal row and its outbound message
 * must be written in the SAME transaction. Sending inline from the engine
 * would mean a network timeout could produce a signal with no alert, or an
 * alert for a signal that was then rolled back — with no record of which.
 *
 * Because Database::transaction() uses savepoints for nested calls, the
 * repository's own transaction joins this one rather than committing early.
 */
final class SignalPublisher
{
    public function __construct(
        private readonly Database $database,
        private readonly SignalRepositoryInterface $signals,
        private readonly TelegramService $telegram,
        private readonly SignalMessagePayload $payloads,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{id:int,code:string,name:string,class_name:string} $strategy
     */
    public function publish(
        SignalResult $result,
        array $strategy,
        StrategyConfig $config,
        StrategyContext $context,
        int $runId,
        int $timeframeId,
        ?DateTimeImmutable $expiresAt
    ): int {
        return $this->database->transaction(function () use (
            $result,
            $strategy,
            $config,
            $context,
            $runId,
            $timeframeId,
            $expiresAt
        ): int {
            $signalId = $this->signals->create(
                $result,
                $strategy['id'],
                $config->id,
                $runId,
                $context->instrumentId,
                $timeframeId,
                $context->at,
                $expiresAt,
                $context->sessionCode(),
                $context->trend()->value
            );

            $signal = $this->signals->find($signalId);

            if ($signal !== null) {
                // Same transaction as the signal above. If this throws, the
                // signal is rolled back too — which is correct: a published
                // signal nobody was told about is worse than none.
                $this->telegram->enqueue(
                    MessageType::NewSignal,
                    sprintf('signal:%s:%s', $signal['uuid'], SignalEventType::Generated->value),
                    $this->payloads->forSignal($signal, $this->signals->targets($signalId), $strategy['name']),
                    $signalId
                );
            }

            $this->logger->info('Signal generated', [
                'event'       => 'signal.generated',
                'signal_id'   => $signalId,
                'strategy'    => $strategy['code'],
                'config'      => $config->version,
                'direction'   => $result->direction?->value,
                'score'       => $result->score,
                'entry'       => $result->entryPrice,
                'stop'        => $result->stopLoss,
                'risk_reward' => $result->riskReward(),
                'session'     => $context->sessionCode(),
            ]);

            return $signalId;
        });
    }

    /**
     * Record a lifecycle transition and enqueue its notification atomically.
     *
     * @return bool False when the transition was illegal and nothing was written.
     */
    public function recordTransition(
        int $signalId,
        SignalEventType $event,
        DateTimeImmutable $at,
        ?float $price = null,
        ?string $notes = null,
        string $triggeredBy = 'SYSTEM',
        ?int $userId = null
    ): bool {
        return $this->database->transaction(function () use (
            $signalId,
            $event,
            $at,
            $price,
            $notes,
            $triggeredBy,
            $userId
        ): bool {
            $recorded = $this->signals->recordEvent($signalId, $event, $at, $price, $notes, $triggeredBy, $userId);

            if (!$recorded) {
                return false;
            }

            $type = MessageType::forSignalEvent($event);

            if ($type === null) {
                return true;
            }

            $signal = $this->signals->find($signalId);

            if ($signal === null) {
                return true;
            }

            $this->telegram->enqueue(
                $type,
                sprintf('signal:%s:%s', $signal['uuid'], $event->value),
                $this->payloads->forSignal(
                    $signal,
                    $this->signals->targets($signalId),
                    (string) ($signal['strategy_name'] ?? $signal['strategy_code'] ?? ''),
                    $price
                ),
                $signalId
            );

            $this->logger->info('Signal transition recorded', [
                'event'      => 'signal.' . strtolower($event->value),
                'signal_id'  => $signalId,
                'price'      => $price,
                'triggered'  => $triggeredBy,
            ]);

            return true;
        });
    }
}
