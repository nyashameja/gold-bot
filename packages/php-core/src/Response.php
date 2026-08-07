<?php

declare(strict_types=1);

namespace Paragon\Core;

/**
 * An HTTP response, sent only when send() is called.
 *
 * Deferring output means middleware can still add headers after a controller
 * has produced its body — the security-header and session middleware both
 * rely on that.
 */
class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        protected array $headers = []
    ) {
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            if (!isset($this->headers['Content-Type'])) {
                header('Content-Type: text/html; charset=utf-8', true);
            }
        }

        echo $this->body;
    }
}
