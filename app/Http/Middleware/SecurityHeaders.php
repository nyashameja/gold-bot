<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\Request;
use GoldBot\Core\Response;

/**
 * Baseline security response headers.
 *
 * Outermost in the stack so the headers are present on error responses too —
 * the pages most likely to reflect user input.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        if ($request->isSecure()) {
            // Only sent over HTTPS: advertising HSTS on a plain-HTTP response
            // is meaningless and can lock out a misconfigured local setup.
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $response->withHeaders($headers);
    }

    /**
     * The TradingView widget is a third-party embed and needs its own origins
     * allowed explicitly. 'unsafe-inline' for styles is required by Alpine's
     * x-show and by the inline chart theming; scripts are not given it, so an
     * injected <script> still cannot execute.
     */
    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://s3.tradingview.com https://www.tradingview-widget.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src https://s.tradingview.com https://www.tradingview-widget.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
