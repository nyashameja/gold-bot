<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Deliberately does NOT boot the Application: the Unit suite must run with no
 * database, no network and no .env (ADR-03). Integration and Feature tests
 * boot it themselves via TestCase.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
