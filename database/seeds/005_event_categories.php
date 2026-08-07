<?php

declare(strict_types=1);

/**
 * Economic event categories and their blackout windows (docs/02 §6).
 *
 * Windows live on the category so "no signals within 30 minutes of NFP" is
 * configuration rather than code — and can differ per event type, which it
 * should: a rate decision moves gold for far longer than retail sales.
 *
 * match_patterns are matched against the normalised event title, so an
 * operator can add a pattern without a deploy.
 */

use Paragon\Core\Database;

return static function (Database $db): int {
    $categories = [
        // code, name, impact, before, after, patterns
        ['INTEREST_RATE', 'Interest Rate Decision', 'HIGH', 60, 90, [
            'federal funds rate', 'interest rate decision', 'fomc statement',
            'fomc economic projections', 'federal open market committee', 'rate statement',
        ]],
        ['NFP', 'Non-Farm Payrolls', 'HIGH', 45, 60, [
            'non farm employment change', 'nonfarm payrolls', 'non farm payrolls',
            'employment situation', 'average hourly earnings', 'unemployment rate',
        ]],
        ['CPI', 'Consumer Price Index', 'HIGH', 45, 60, [
            'cpi', 'consumer price index', 'core cpi',
        ]],
        ['PPI', 'Producer Price Index', 'MEDIUM', 30, 30, [
            'ppi', 'producer price index', 'core ppi',
        ]],
        ['GDP', 'Gross Domestic Product', 'HIGH', 30, 45, [
            'gdp', 'gross domestic product',
        ]],
        ['RETAIL_SALES', 'Retail Sales', 'MEDIUM', 30, 30, [
            'retail sales', 'core retail sales', 'advance monthly sales for retail',
        ]],
        ['FED_SPEECH', 'Fed Speech', 'MEDIUM', 20, 30, [
            'fed chair', 'fomc member', 'speaks', 'testimony', 'press conference',
        ]],
        ['PCE', 'PCE Price Index', 'HIGH', 30, 45, [
            'pce price index', 'core pce', 'personal income and outlays',
        ]],
        ['HOLIDAY', 'Bank Holiday', 'HOLIDAY', 0, 0, [
            'bank holiday', 'holiday',
        ]],
    ];

    $affected = 0;

    foreach ($categories as [$code, $name, $impact, $before, $after, $patterns]) {
        $exists = $db->scalar('SELECT COUNT(*) FROM event_categories WHERE code = ?', [$code]);

        $affected += $db->upsert(
            'event_categories',
            [
                'code'                    => $code,
                'name'                    => $name,
                'default_impact'          => $impact,
                'blackout_minutes_before' => $before,
                'blackout_minutes_after'  => $after,
                'match_patterns'          => json_encode($patterns, JSON_UNESCAPED_SLASHES),
                'is_active'               => 1,
            ],
            // Patterns follow the code; an operator's tuned windows do not.
            (int) $exists > 0
                ? ['name', 'default_impact', 'match_patterns']
                : ['name', 'default_impact', 'blackout_minutes_before', 'blackout_minutes_after', 'match_patterns', 'is_active']
        );
    }

    return $affected;
};
