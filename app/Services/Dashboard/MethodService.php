<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;

/**
 * The 714 Method page: the rubric as it is actually configured.
 *
 * This page renders the ACTIVE CONFIG VERSION rather than a hand-written
 * description of the method. That is the whole point of ADR-06 — the rules are
 * data, the strategy class is an interpreter of that data, and a page that
 * described the method in prose would go out of date the first time anyone
 * retuned it. What you see here is what the engine will do on the next run.
 *
 * The score distribution beside it answers the question that decides the
 * threshold: how many candles score near the cutoff? A threshold with nothing
 * either side of it is not selective, it is simply unreachable.
 */
final class MethodService
{
    public function __construct(
        private readonly StrategyRepositoryInterface $strategies,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>|null Null when the strategy code is unknown.
     */
    public function board(string $code, int $days = 30): array|null
    {
        $strategy = $this->strategies->findByCode($code);

        if ($strategy === null) {
            return null;
        }

        $strategyId = (int) $strategy['id'];
        $config = $this->strategies->activeConfig($strategyId);
        $now = $this->clock->now();
        $since = $now->modify('-' . max(1, min($days, 365)) . ' days');

        $runs = $this->strategies->recentRuns($strategyId, 50);

        return [
            'strategy' => [
                'code'        => (string) $strategy['code'],
                'name'        => (string) $strategy['name'],
                'description' => (string) ($strategy['description'] ?? ''),
                'enabled'     => (int) $strategy['is_enabled'] === 1,
                'class'       => (string) $strategy['class_name'],
            ],
            'config'       => $config === null ? null : $this->describeConfig($config),
            'history'      => array_map(
                static fn (array $row): array => [
                    'version'      => (int) $row['version'],
                    'is_active'    => (int) $row['is_active'] === 1,
                    'notes'        => $row['notes'] === null ? null : (string) $row['notes'],
                    'created_at'   => (string) $row['created_at'],
                    'created_by'   => $row['created_by_name'] === null ? 'system' : (string) $row['created_by_name'],
                    // How many signals this version produced. A version with
                    // none is untested, however good its rules look.
                    'signal_count' => (int) $row['signal_count'],
                ],
                $this->strategies->configHistory($strategyId)
            ),
            'distribution' => $this->strategies->scoreDistribution($strategyId, $since),
            'runs'         => array_map(
                static fn (array $row): array => [
                    'evaluated_at' => (string) $row['evaluated_at'],
                    'candle_at'    => (string) $row['candle_open_time'],
                    'direction'    => $row['direction'] === null ? null : (string) $row['direction'],
                    'score'        => $row['score'] === null ? null : (float) $row['score'],
                    'passed'       => (int) $row['passed'] === 1,
                    'reason'       => $row['rejection_reason'] === null ? null : (string) $row['rejection_reason'],
                    'duration_ms'  => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
                ],
                $runs
            ),
            'window' => ['days' => $days, 'since' => $since->format(DATE_ATOM)],
            // The analysis cron runs on the signal timeframe's cadence; an
            // hour of grace covers every timeframe we support.
            'age'    => DataAge::since(
                $runs === [] ? null : new \DateTimeImmutable((string) $runs[0]['evaluated_at'], new \DateTimeZone('UTC')),
                $now,
                3600
            )->toArray(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function strategies(): array
    {
        return array_map(
            static fn (array $s): array => [
                'code'    => (string) $s['code'],
                'name'    => (string) $s['name'],
                'enabled' => (int) $s['is_enabled'] === 1,
            ],
            $this->strategies->all()
        );
    }

    /**
     * Flatten the config into something a table can render, without losing the
     * structure that makes a pillar meaningful.
     *
     * @return array<string,mixed>
     */
    private function describeConfig(StrategyConfig $config): array
    {
        $pillars = [];
        $totalWeight = 0.0;

        /** @var array<string,mixed> $definition */
        foreach ($config->array('pillars') as $name => $definition) {
            $weight = (float) ($definition['weight'] ?? 0);
            $totalWeight += $weight;

            $rules = array_map(
                static function (array $rule): array {
                    // Everything except the four structural keys is a rule
                    // parameter; listing them generically means a new rule type
                    // renders correctly without touching this page.
                    $parameters = array_diff_key($rule, array_flip(['id', 'type', 'points']));

                    return [
                        'id'         => (string) ($rule['id'] ?? ''),
                        'type'       => (string) ($rule['type'] ?? ''),
                        'points'     => (float) ($rule['points'] ?? 0),
                        'parameters' => $parameters,
                    ];
                },
                array_values((array) ($definition['rules'] ?? []))
            );

            $pillars[] = [
                'name'    => (string) $name,
                'weight'  => $weight,
                // A gate is a hard requirement: fail it and the signal is
                // rejected no matter how high the other pillars scored.
                'gate'    => (bool) ($definition['gate'] ?? false),
                'min_raw' => isset($definition['min_raw']) ? (float) $definition['min_raw'] : null,
                'rules'   => $rules,
                'points_available' => array_sum(array_column($rules, 'points')),
            ];
        }

        return [
            'version'          => $config->version,
            'signal_timeframe' => $config->string('signal_timeframe', 'M15'),
            'min_score'        => $config->float('min_score', 70.0),
            'min_risk_reward'  => $config->float('min_risk_reward', 1.5),
            'direction'        => $config->array('direction'),
            'stop'             => $config->array('stop'),
            'targets'          => $config->array('targets'),
            'pillars'          => $pillars,
            'total_weight'     => $totalWeight,
            // A rubric whose weights do not sum to 100 still works — the score
            // is normalised — but it almost always means someone edited one
            // weight and forgot the others, so it is worth saying out loud.
            'weights_balanced' => abs($totalWeight - 100.0) < 0.001,
            'raw'              => $config->all(),
        ];
    }
}
