<?php

declare(strict_types=1);

/**
 * Instruments, timeframes and market sessions, seeded from config/market.php.
 *
 * The config file is the initial state; once seeded the database is
 * authoritative, so an operator activating M5 in the UI is not reverted by
 * the next deploy. That is why the upserts below only refresh descriptive
 * columns and deliberately leave `is_active` alone on existing rows.
 */

use GoldBot\Core\Database;

return static function (Database $db): int {
    /** @var array<string,mixed> $market */
    $market = require dirname(__DIR__, 2) . '/config/market.php';

    $affected = 0;

    foreach ($market['instruments'] as $instrument) {
        $exists = $db->scalar('SELECT COUNT(*) FROM instruments WHERE symbol = ?', [$instrument['symbol']]);

        $affected += $db->upsert(
            'instruments',
            [
                'symbol'          => $instrument['symbol'],
                'provider_symbol' => $instrument['provider_symbol'],
                'name'            => $instrument['name'],
                'asset_class'     => $instrument['asset_class'],
                'price_precision' => $instrument['price_precision'],
                'pip_size'        => $instrument['pip_size'],
                'contract_size'   => $instrument['contract_size'],
                'is_active'       => $instrument['is_active'] ? 1 : 0,
            ],
            (int) $exists > 0
                ? ['provider_symbol', 'name', 'asset_class', 'price_precision', 'pip_size', 'contract_size']
                : ['provider_symbol', 'name', 'asset_class', 'price_precision', 'pip_size', 'contract_size', 'is_active']
        );
    }

    foreach ($market['timeframes'] as $timeframe) {
        $exists = $db->scalar('SELECT COUNT(*) FROM timeframes WHERE code = ?', [$timeframe['code']]);

        $affected += $db->upsert(
            'timeframes',
            [
                'code'              => $timeframe['code'],
                'minutes'           => $timeframe['minutes'],
                'provider_interval' => $timeframe['provider_interval'],
                'sort_order'        => $timeframe['sort_order'],
                'is_active'         => $timeframe['is_active'] ? 1 : 0,
            ],
            (int) $exists > 0
                ? ['minutes', 'provider_interval', 'sort_order']
                : ['minutes', 'provider_interval', 'sort_order', 'is_active']
        );
    }

    foreach ($market['sessions'] as $session) {
        $affected += $db->upsert(
            'market_sessions',
            [
                'code'       => $session['code'],
                'name'       => $session['name'],
                'open_time'  => $session['open'] . ':00',
                'close_time' => $session['close'] . ':00',
                'timezone'   => $session['timezone'],
                'is_active'  => 1,
            ],
            ['name', 'open_time', 'close_time', 'timezone']
        );
    }

    return $affected;
};
