<?php

declare(strict_types=1);

/**
 * Strategies and their initial configuration versions (docs/02 §7).
 *
 * Configs are immutable once written (ADR-06), so this seed only ever creates
 * version 1 — it never rewrites an existing one. Retuning happens through the
 * UI or `strategy:config`, which appends a new version and moves the active
 * pointer, leaving every past signal attributable to what actually produced it.
 */

use GoldBot\Core\Database;
use GoldBot\Domain\Strategy\Strategies\EmaCrossStrategy;
use GoldBot\Domain\Strategy\Strategies\SevenFourteenStrategy;

return static function (Database $db): int {
    $affected = 0;

    $strategies = [
        [
            'code'        => SevenFourteenStrategy::CODE,
            'name'        => '714 Method',
            'description' => 'Five-pillar weighted rubric. Awaiting the real rules (docs/00 Q1).',
            'class_name'  => SevenFourteenStrategy::class,
            // Disabled deliberately: the shipped config is a placeholder, not
            // the 714 method, and must not publish signals as though it were.
            'is_enabled'  => 0,
            'sort_order'  => 10,
        ],
        [
            'code'        => EmaCrossStrategy::CODE,
            'name'        => 'EMA Trend',
            'description' => 'Fully specified reference strategy. Verifies the pipeline end to end and serves as the baseline any tuned 714 config should beat.',
            'class_name'  => EmaCrossStrategy::class,
            'is_enabled'  => 0,
            'sort_order'  => 20,
        ],
    ];

    foreach ($strategies as $strategy) {
        $exists = $db->scalar('SELECT COUNT(*) FROM strategies WHERE code = ?', [$strategy['code']]);

        $affected += $db->upsert(
            'strategies',
            $strategy,
            // The class follows the code; an operator's enabled flag does not.
            (int) $exists > 0
                ? ['name', 'description', 'class_name', 'sort_order']
                : ['name', 'description', 'class_name', 'sort_order', 'is_enabled']
        );
    }

    /**
     * PLACEHOLDER — this is NOT the 714 method (docs/00 §3, Q1).
     *
     * It is conventional trend-pullback logic, included so the rubric engine
     * has something coherent to exercise and so the shape of a real config is
     * self-documenting. The pillar names are the five the brief specifies; the
     * rules inside them are invented and carry no claim to edge.
     *
     * Supplying the real method means appending a new config version. No PHP
     * changes are needed — that is the point of ADR-06.
     */
    $sevenFourteen = [
        'signal_timeframe' => 'M15',
        'min_score'        => 70,
        'min_risk_reward'  => 1.5,
        'direction'        => ['source' => 'trend', 'timeframe' => 'H4'],
        'stop'             => ['type' => 'atr', 'multiplier' => 1.5],
        'targets'          => [
            ['r' => 1.0, 'close_percent' => 50],
            ['r' => 2.0, 'close_percent' => 30],
            ['r' => 3.0, 'close_percent' => 20],
        ],
        'pillars' => [
            'TREND' => [
                'weight'  => 25,
                'gate'    => true,
                'min_raw' => 60,
                'rules'   => [
                    ['id' => 'h4_trend', 'type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 50],
                    ['id' => 'h1_trend', 'type' => 'trend', 'timeframe' => 'H1', 'expect' => 'with_direction', 'points' => 25],
                    ['id' => 'above_ema200', 'type' => 'price_vs_indicator', 'timeframe' => 'H1', 'indicator' => 'ema_200', 'expect' => 'above_if_buy', 'points' => 25],
                ],
            ],
            'STRUCTURE' => [
                'weight' => 20,
                'rules'  => [
                    ['id' => 'bos_with_trend', 'type' => 'structure', 'expect' => 'bos_with_direction', 'points' => 60],
                    ['id' => 'no_choch_against', 'type' => 'structure', 'expect' => 'no_choch_against', 'points' => 40],
                ],
            ],
            'PULLBACK' => [
                'weight' => 20,
                'rules'  => [
                    ['id' => 'retracement_depth', 'type' => 'pullback_depth', 'lookback' => 20, 'min' => 0.236, 'max' => 0.618, 'tolerance' => 0.15, 'points' => 60],
                    ['id' => 'near_ema50', 'type' => 'distance_to_level', 'level_types' => ['SUPPORT', 'RESISTANCE', 'DEMAND_ZONE', 'SUPPLY_ZONE'], 'max_atr' => 1.5, 'points' => 40],
                ],
            ],
            'CONFIRMATION' => [
                'weight' => 20,
                'rules'  => [
                    ['id' => 'candle_direction', 'type' => 'candle', 'expect' => 'with_direction', 'points' => 40],
                    ['id' => 'strong_close', 'type' => 'candle', 'expect' => 'strong_close', 'points' => 30],
                    ['id' => 'rsi_not_extreme', 'type' => 'indicator_range', 'timeframe' => 'M15', 'indicator' => 'rsi_14', 'min' => 35, 'max' => 70, 'tolerance' => 10, 'points' => 30],
                ],
            ],
            'RISK' => [
                'weight'  => 15,
                'gate'    => true,
                'min_raw' => 50,
                'rules'   => [
                    ['id' => 'volatility_sane', 'type' => 'volatility', 'timeframe' => 'M15', 'min' => 0.0002, 'max' => 0.02, 'points' => 50],
                    ['id' => 'active_session', 'type' => 'session', 'in' => ['LONDON', 'NEW_YORK'], 'points' => 50],
                ],
            ],
        ],
    ];

    /**
     * A fully specified reference strategy.
     *
     * Unlike the 714 placeholder this makes a real, complete claim: trade with
     * the H1 EMA trend, on a pullback, confirmed by the candle. It exists so
     * the pipeline can be verified against a known answer, and remains useful
     * afterwards as the bar a tuned 714 config has to clear.
     */
    $emaCross = [
        'signal_timeframe' => 'M15',
        'min_score'        => 65,
        'min_risk_reward'  => 1.5,
        'direction'        => ['source' => 'ema', 'timeframe' => 'H1', 'fast' => 'ema_50', 'slow' => 'ema_200'],
        'stop'             => ['type' => 'atr', 'multiplier' => 1.5],
        'targets'          => [
            ['r' => 1.5, 'close_percent' => 50],
            ['r' => 3.0, 'close_percent' => 50],
        ],
        'pillars' => [
            'TREND' => [
                'weight'  => 50,
                'gate'    => true,
                'min_raw' => 100,
                'rules'   => [
                    ['id' => 'ema_stack', 'type' => 'indicator_vs_indicator', 'timeframe' => 'H1', 'left' => 'ema_50', 'right' => 'ema_200', 'expect' => 'left_above_if_buy', 'points' => 50],
                    ['id' => 'price_side', 'type' => 'price_vs_indicator', 'timeframe' => 'H1', 'indicator' => 'ema_200', 'expect' => 'above_if_buy', 'points' => 50],
                ],
            ],
            'ENTRY' => [
                'weight' => 30,
                'rules'  => [
                    ['id' => 'pullback', 'type' => 'pullback_depth', 'lookback' => 20, 'min' => 0.15, 'max' => 0.7, 'tolerance' => 0.2, 'points' => 100],
                ],
            ],
            'CONFIRMATION' => [
                'weight' => 20,
                'rules'  => [
                    ['id' => 'candle_direction', 'type' => 'candle', 'expect' => 'with_direction', 'points' => 100],
                ],
            ],
        ],
    ];

    foreach ([
        [SevenFourteenStrategy::CODE, $sevenFourteen, 'Placeholder rubric — NOT the 714 method. Awaiting the real rules (docs/00 Q1).'],
        [EmaCrossStrategy::CODE, $emaCross, 'Reference configuration. Fully specified.'],
    ] as [$code, $config, $notes]) {
        $strategyId = $db->scalar('SELECT id FROM strategies WHERE code = ?', [$code]);

        if ($strategyId === null) {
            continue;
        }

        // Only ever seed version 1. Configs are immutable, so a re-seed must
        // never overwrite a version the operator has since tuned.
        $hasConfig = (int) $db->scalar(
            'SELECT COUNT(*) FROM strategy_configs WHERE strategy_id = ?',
            [(int) $strategyId]
        );

        if ($hasConfig > 0) {
            continue;
        }

        $db->insert('strategy_configs', [
            'strategy_id'  => (int) $strategyId,
            'version'      => 1,
            'config'       => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'notes'        => $notes,
            'is_active'    => 1,
            'activated_at' => date('Y-m-d H:i:s'),
        ]);

        $affected++;
    }

    return $affected;
};
