<?php

declare(strict_types=1);

namespace GoldBot\Services\Telegram;

use GoldBot\Domain\Market\Enums\Direction;

/**
 * Turns a signal row into the flat values a template renders.
 *
 * Formatting lives here rather than in the template so the numbers are
 * consistent across every message type — a stop shown to two decimals in one
 * alert and five in another looks like two different prices.
 */
final class SignalMessagePayload
{
    public function __construct(private readonly int $pricePrecision = 2)
    {
    }

    /**
     * @param array<string,mixed>       $signal
     * @param list<array<string,mixed>> $targets
     * @return array<string,mixed>
     */
    public function forSignal(array $signal, array $targets, string $strategyName = '', ?float $currentPrice = null): array
    {
        $direction = Direction::tryFrom((string) ($signal['direction'] ?? '')) ?? Direction::Buy;
        $entry = (float) ($signal['entry_price'] ?? 0);
        $stop = (float) ($signal['stop_loss'] ?? 0);

        $payload = [
            'uuid'          => (string) ($signal['uuid'] ?? ''),
            'strategy'      => $strategyName,
            'symbol'        => 'XAU/USD',
            'direction'     => $direction->value,
            'direction_word' => $direction->isBuy() ? 'BUY' : 'SELL',
            'direction_icon' => $direction->isBuy() ? '🟢' : '🔴',
            'score'         => number_format((float) ($signal['score'] ?? 0), 1),
            'entry'         => $this->price($entry),
            'stop'          => $this->price($stop),
            'risk_reward'   => $signal['risk_reward'] === null ? '—' : number_format((float) $signal['risk_reward'], 2),
            'session'       => (string) ($signal['session_code'] ?? '—'),
            'state'         => (string) ($signal['state'] ?? ''),
            'generated_at'  => (string) ($signal['generated_at'] ?? ''),
            'current_price' => $currentPrice === null ? '' : $this->price($currentPrice),
        ];

        $lines = [];

        foreach ($targets as $index => $target) {
            $level = (int) $target['level'];
            $price = $this->price((float) $target['price']);

            $payload['tp' . $level] = $price;
            $payload['tp' . $level . '_r'] = $target['r_multiple'] === null
                ? ''
                : number_format((float) $target['r_multiple'], 1) . 'R';

            $hit = $target['hit_at'] !== null;

            $lines[] = sprintf(
                '%s TP%d: %s%s',
                $hit ? '✅' : '🎯',
                $level,
                $price,
                $target['r_multiple'] === null ? '' : sprintf(' (%.1fR)', (float) $target['r_multiple'])
            );

            unset($index);
        }

        // Pre-formatted so a message with two targets and one with three both
        // read correctly without the template branching.
        $payload['targets_block'] = implode("\n", $lines);
        $payload['target_count'] = count($targets);

        if ($currentPrice !== null && $entry > 0.0) {
            $risk = abs($entry - $stop);

            $payload['move_r'] = $risk > 0.0
                ? number_format((($currentPrice - $entry) * $direction->sign()) / $risk, 2) . 'R'
                : '—';
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    public function forSystemAlert(string $component, string $message, string $severity = 'error'): array
    {
        return [
            'component' => $component,
            'message'   => $message,
            'severity'  => strtoupper($severity),
            'icon'      => $severity === 'warning' ? '⚠️' : '🚨',
        ];
    }

    private function price(float $value): string
    {
        return number_format($value, $this->pricePrecision, '.', ',');
    }
}
