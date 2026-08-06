<?php

declare(strict_types=1);

namespace GoldBot\Core;

use ErrorException;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use Throwable;

/**
 * Converts PHP errors into exceptions and routes everything to the logger.
 *
 * Two behaviours worth stating explicitly:
 *
 * 1. Notices and warnings become ErrorExceptions. A warning that a strategy
 *    silently ignores — an undefined array index in a provider response, say —
 *    is exactly how a malformed candle reaches the signal engine looking valid.
 *
 * 2. In production nothing about the failure reaches the response body. Stack
 *    traces name file paths, class names and sometimes credentials.
 */
final class ErrorHandler
{
    private bool $registered = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        private readonly bool $isCli = false
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', $this->debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    /**
     * Restore the previous handlers.
     *
     * The shutdown function cannot be unregistered — PHP offers no way — but
     * it is harmless once this handler is no longer the active one. Tests use
     * this so each case leaves the global handler state as it found it.
     */
    public function restore(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();

        $this->registered = false;
    }

    /**
     * @throws ErrorException
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Respect the error_reporting mask so @-suppressed calls — which the
        // cache and logger use deliberately for best-effort filesystem writes —
        // do not become fatal.
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    public function handleException(Throwable $e): void
    {
        $this->logger->critical($e->getMessage(), [
            'event'     => 'app.exception',
            'exception' => $e,
        ]);

        if ($this->isCli) {
            fwrite(STDERR, sprintf(
                "[%s] %s in %s:%d%s",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                PHP_EOL
            ));

            if ($this->debug) {
                fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
            }

            exit(1);
        }

        $this->renderHttpError($e);
    }

    /**
     * Catch fatals that bypass the exception handler — memory exhaustion,
     * timeouts, and errors inside the handler itself. Without this a fatal in
     * a cron task produces no record at all, and the task simply appears to
     * have stopped.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $this->logger->critical($error['message'], [
            'event' => 'app.fatal',
            'file'  => $error['file'] . ':' . $error['line'],
            'type'  => $error['type'],
        ]);

        if ($this->isCli) {
            fwrite(STDERR, 'Fatal: ' . $error['message'] . PHP_EOL);

            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
        }
    }

    private function renderHttpError(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->statusCode() : 500;

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }

        if ($this->debug) {
            printf(
                '<pre style="font:14px/1.5 monospace;padding:24px;background:#0b0d10;color:#e5e7eb">%s: %s%s%s:%d%s%s</pre>',
                e($e::class),
                e($e->getMessage()),
                PHP_EOL,
                e($e->getFile()),
                $e->getLine(),
                PHP_EOL . PHP_EOL,
                e($e->getTraceAsString())
            );

            return;
        }

        $message = $e instanceof HttpException
            ? $e->getMessage()
            : 'An unexpected error occurred. The incident has been logged.';

        printf(
            '<!doctype html><meta charset="utf-8"><title>Error %d</title>'
            . '<div style="font:16px/1.6 system-ui,sans-serif;background:#0b0d10;color:#e5e7eb;'
            . 'min-height:100vh;display:grid;place-items:center;margin:0">'
            . '<div style="text-align:center;padding:24px">'
            . '<h1 style="font-size:56px;margin:0;color:#d4af37">%d</h1><p>%s</p></div></div>',
            $status,
            $status,
            e($message)
        );
    }
}
