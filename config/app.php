<?php

declare(strict_types=1);

use GoldBot\Core\Env;

return [
    'name'     => Env::string('APP_NAME', 'Gold Bot'),
    'env'      => Env::string('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => Env::string('APP_URL', 'http://localhost'),

    // Storage is always UTC (docs/02 §1). This is the *display* default for
    // users who have not set a preference; it never affects what is written.
    'timezone' => Env::string('APP_TIMEZONE', 'UTC'),

    'key'      => Env::string('APP_KEY'),

    'session'  => [
        'name'            => Env::string('SESSION_NAME', 'goldbot_session'),
        'lifetime'        => Env::int('SESSION_LIFETIME_MINUTES', 120),
        'idle_timeout'    => Env::int('SESSION_IDLE_TIMEOUT_MINUTES', 30),
        'secure_cookie'   => Env::bool('SESSION_SECURE_COOKIE', true),
        'same_site'       => 'Lax',
    ],

    'auth' => [
        'max_login_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes'    => Env::int('LOGIN_LOCKOUT_MINUTES', 15),
        // Argon2id parameters. The defaults are PHP's, which are tuned for
        // general-purpose hardware; shared hosting may need the memory cost
        // reduced if hashing becomes slow enough to affect login latency.
        'hash_algorithm'     => PASSWORD_ARGON2ID,
        'hash_options'       => [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ],
    ],
];
