<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\Config;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Infrastructure\Session\DatabaseSessionHandler;

/**
 * Starts the database-backed session with hardened cookie parameters.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(
        private readonly DatabaseSessionHandler $handler,
        private readonly Config $config
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_set_save_handler($this->handler, true);

            session_set_cookie_params([
                'lifetime' => 0, // Session cookie: cleared when the browser closes.
                'path'     => '/',
                'domain'   => '',
                'secure'   => $this->config->bool('app.session.secure_cookie', true),
                'httponly' => true, // Unreadable from JavaScript, so XSS cannot steal it.
                'samesite' => $this->config->string('app.session.same_site', 'Lax'),
            ]);

            session_name($this->config->string('app.session.name', 'goldbot_session'));

            // Never accept a session id from the query string — that is how
            // session-fixation links work.
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');

            session_start();
        }

        $response = $next($request);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }
}
