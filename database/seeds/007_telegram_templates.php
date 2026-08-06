<?php

declare(strict_types=1);

/**
 * Telegram message templates (docs/02 §8).
 *
 * Stored rather than hardcoded because wording changes far more often than
 * logic — copy edits should not require a deploy. Placeholders are {{ name }},
 * HTML-escaped; {{{ name }}} emits raw for pre-formatted blocks.
 *
 * Emoji are used sparingly and only where they carry meaning: direction,
 * outcome, severity. A message where everything is decorated is a message
 * where nothing stands out.
 */

use GoldBot\Core\Database;
use GoldBot\Domain\Notification\MessageType;

return static function (Database $db): int {
    $templates = [
        [
            MessageType::NewSignal->value,
            'New signal',
            <<<'TPL'
            {{ direction_icon }} <b>{{ direction_word }} {{ symbol }}</b>

            <b>Entry</b>     <code>{{ entry }}</code>
            <b>Stop</b>      <code>{{ stop }}</code>
            <b>R:R</b>       {{ risk_reward }}

            {{{ targets_block }}}

            <i>{{ strategy }} · score {{ score }}/100 · {{ session }}</i>
            TPL,
        ],
        [
            MessageType::EntryActivated->value,
            'Entry activated',
            <<<'TPL'
            ▶️ <b>Entry filled — {{ direction_word }} {{ symbol }}</b>

            Filled at <code>{{ entry }}</code>
            Stop <code>{{ stop }}</code>

            {{{ targets_block }}}
            TPL,
        ],
        [
            MessageType::Tp1Hit->value,
            'Take profit 1 hit',
            <<<'TPL'
            ✅ <b>TP1 hit — {{ direction_word }} {{ symbol }}</b>

            Price <code>{{ current_price }}</code> ({{ move_r }})
            Stop moved to entry.
            TPL,
        ],
        [
            MessageType::Tp2Hit->value,
            'Take profit 2 hit',
            <<<'TPL'
            ✅ <b>TP2 hit — {{ direction_word }} {{ symbol }}</b>

            Price <code>{{ current_price }}</code> ({{ move_r }})
            TPL,
        ],
        [
            MessageType::Tp3Hit->value,
            'Take profit 3 hit',
            <<<'TPL'
            🏁 <b>TP3 hit — trade closed</b>

            {{ direction_word }} {{ symbol }} closed at <code>{{ current_price }}</code>
            Result <b>{{ move_r }}</b>
            TPL,
        ],
        [
            MessageType::Breakeven->value,
            'Moved to breakeven',
            <<<'TPL'
            🛡️ <b>Stop moved to breakeven</b>

            {{ direction_word }} {{ symbol }} · entry <code>{{ entry }}</code>
            The position is now risk-free.
            TPL,
        ],
        [
            MessageType::StopLoss->value,
            'Stop loss hit',
            <<<'TPL'
            ❌ <b>Stop hit — {{ direction_word }} {{ symbol }}</b>

            Closed at <code>{{ stop }}</code> ({{ move_r }})
            TPL,
        ],
        [
            MessageType::Cancelled->value,
            'Signal cancelled',
            <<<'TPL'
            🚫 <b>Signal cancelled</b>

            {{ direction_word }} {{ symbol }} · entry <code>{{ entry }}</code>
            TPL,
        ],
        [
            MessageType::Expired->value,
            'Signal expired',
            <<<'TPL'
            ⌛ <b>Signal expired</b>

            {{ direction_word }} {{ symbol }} never filled at <code>{{ entry }}</code>.
            TPL,
        ],
        [
            MessageType::DailySummary->value,
            'Daily summary',
            <<<'TPL'
            📊 <b>Daily summary — {{ date }}</b>

            Signals    {{ total_signals }}
            Wins       {{ wins }}
            Losses     {{ losses }}
            Win rate   {{ win_rate }}%
            Net        <b>{{ total_r }}R</b>
            TPL,
        ],
        [
            MessageType::WeeklySummary->value,
            'Weekly summary',
            <<<'TPL'
            📈 <b>Weekly summary — {{ period }}</b>

            Signals       {{ total_signals }}
            Win rate      {{ win_rate }}%
            Profit factor {{ profit_factor }}
            Net           <b>{{ total_r }}R</b>
            TPL,
        ],
        [
            MessageType::MonthlySummary->value,
            'Monthly summary',
            <<<'TPL'
            🗓️ <b>Monthly summary — {{ period }}</b>

            Signals       {{ total_signals }}
            Win rate      {{ win_rate }}%
            Profit factor {{ profit_factor }}
            Max drawdown  {{ max_drawdown_r }}R
            Net           <b>{{ total_r }}R</b>
            TPL,
        ],
        [
            MessageType::SystemError->value,
            'System error',
            <<<'TPL'
            {{ icon }} <b>{{ severity }} — {{ component }}</b>

            {{ message }}
            TPL,
        ],
        [
            MessageType::NewsWarning->value,
            'High-impact news warning',
            <<<'TPL'
            📰 <b>High-impact news</b>

            {{ title }} ({{ currency }}) at {{ scheduled_at }} UTC.
            Signals are suppressed either side of the release.
            TPL,
        ],
        [
            MessageType::ApiFailure->value,
            'API failure',
            <<<'TPL'
            🚨 <b>Provider unavailable — {{ component }}</b>

            {{ message }}
            Market data may be stale.
            TPL,
        ],
    ];

    $affected = 0;

    foreach ($templates as [$code, $name, $body]) {
        $exists = $db->scalar('SELECT COUNT(*) FROM telegram_templates WHERE code = ?', [$code]);

        $affected += $db->upsert(
            'telegram_templates',
            [
                'code'       => $code,
                'name'       => $name,
                'body'       => $body,
                'parse_mode' => 'HTML',
                'is_active'  => 1,
            ],
            // An operator's edited wording is never overwritten by a deploy.
            (int) $exists > 0 ? ['name'] : ['name', 'body', 'parse_mode', 'is_active']
        );
    }

    return $affected;
};
