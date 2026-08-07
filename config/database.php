<?php

declare(strict_types=1);

use Paragon\Core\Env;

return [
    'host'      => Env::string('DB_HOST', '127.0.0.1'),
    'port'      => Env::int('DB_PORT', 3306),
    'database'  => Env::string('DB_DATABASE'),
    'username'  => Env::string('DB_USERNAME'),
    'password'  => Env::string('DB_PASSWORD'),
    'charset'   => Env::string('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::string('DB_COLLATION', 'utf8mb4_unicode_ci'),

    'migrations' => [
        'table' => 'migrations',
        'path'  => 'database/migrations',
    ],

    'seeds' => [
        'path' => 'database/seeds',
    ],
];
