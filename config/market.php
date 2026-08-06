<?php

declare(strict_types=1);

/**
 * Instrument and timeframe reference data.
 *
 * These values seed the `instruments`, `timeframes` and `market_sessions`
 * tables. Once seeded the database is authoritative — this file is the initial
 * state, not a runtime lookup, so an operator adding an instrument in the UI
 * is not overwritten by the next deploy.
 */
return [
    'default_instrument' => 'XAU/USD',

    'instruments' => [
        [
            'symbol'          => 'XAU/USD',
            'provider_symbol' => 'XAU/USD',
            'name'            => 'Gold vs US Dollar',
            'asset_class'     => 'METAL',
            'price_precision' => 2,
            'pip_size'        => '0.10000',
            'contract_size'   => '100.00',
            'is_active'       => true,
        ],
    ],

    'timeframes' => [
        // provider_interval is Twelve Data's `interval` parameter value.
        ['code' => 'M5',  'minutes' => 5,    'provider_interval' => '5min',  'sort_order' => 1, 'is_active' => false],
        ['code' => 'M15', 'minutes' => 15,   'provider_interval' => '15min', 'sort_order' => 2, 'is_active' => true],
        ['code' => 'H1',  'minutes' => 60,   'provider_interval' => '1h',    'sort_order' => 3, 'is_active' => true],
        ['code' => 'H4',  'minutes' => 240,  'provider_interval' => '4h',    'sort_order' => 4, 'is_active' => true],
        ['code' => 'D1',  'minutes' => 1440, 'provider_interval' => '1day',  'sort_order' => 5, 'is_active' => true],
    ],

    /**
     * Sessions are stored as local times plus an IANA timezone, never as fixed
     * UTC offsets: London and New York change to and from DST on different
     * dates, so hardcoded offsets are wrong for several weeks a year in a way
     * that only shows up as a strange session breakdown months later
     * (docs/02 §4).
     */
    'sessions' => [
        ['code' => 'SYDNEY',   'name' => 'Sydney',   'open' => '07:00', 'close' => '16:00', 'timezone' => 'Australia/Sydney'],
        ['code' => 'TOKYO',    'name' => 'Tokyo',    'open' => '09:00', 'close' => '18:00', 'timezone' => 'Asia/Tokyo'],
        ['code' => 'LONDON',   'name' => 'London',   'open' => '08:00', 'close' => '16:30', 'timezone' => 'Europe/London'],
        ['code' => 'NEW_YORK', 'name' => 'New York', 'open' => '08:00', 'close' => '17:00', 'timezone' => 'America/New_York'],
    ],

    /**
     * Fetch cadence per timeframe, aligned to candle close rather than a fixed
     * clock (docs/01 §5). The settle margin gives the provider time to publish
     * the closed bar — requesting at exactly :00 frequently returns the
     * previous candle still marked open.
     */
    'fetch' => [
        'settle_seconds' => [
            'M15' => 20,
            'H1'  => 20,
            'H4'  => 30,
            'D1'  => 60,
        ],
        // Bars requested per poll. Enough to heal a short outage without a
        // separate backfill, small enough not to waste API credits.
        'poll_output_size'     => 100,
        'backfill_output_size' => 5000,
    ],
];
