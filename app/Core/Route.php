<?php

declare(strict_types=1);

namespace GoldBot\Core;

/**
 * A single route definition.
 *
 * Patterns use {name} placeholders, which compile to a named capture matching
 * anything but a slash. {name:\d+} constrains the match.
 */
final class Route
{
    private string $compiled;

    /** @var list<string> */
    private array $parameterNames = [];

    /** @var array<string,string> */
    private array $parameters = [];

    /**
     * @param class-string     $controller
     * @param list<class-string> $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $controller,
        public readonly string $action,
        public readonly array $middleware = [],
        public readonly ?string $name = null
    ) {
        $this->compiled = $this->compile($pattern);
    }

    public function matches(string $method, string $path): bool
    {
        if ($this->method !== $method) {
            return false;
        }

        if (preg_match($this->compiled, $path, $matches) !== 1) {
            return false;
        }

        $parameters = [];

        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name])) {
                $parameters[$name] = $matches[$name];
            }
        }

        $this->parameters = $parameters;

        return true;
    }

    /** @return array<string,string> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @param array<string,string|int> $parameters */
    public function url(array $parameters = []): string
    {
        $url = $this->pattern;

        foreach ($parameters as $key => $value) {
            $url = preg_replace('/\{' . preg_quote((string) $key, '/') . '(:[^}]+)?\}/', (string) $value, $url) ?? $url;
        }

        return $url;
    }

    private function compile(string $pattern): string
    {
        $this->parameterNames = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $m): string {
                $this->parameterNames[] = $m[1];

                return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            },
            // Escape everything else first so a literal dot in a path cannot
            // act as a wildcard.
            preg_quote($pattern, '#')
        );

        // preg_quote escaped the placeholder braces; undo that for the parts
        // the callback did not consume.
        $regex = str_replace(['\{', '\}'], ['{', '}'], (string) $regex);

        return '#^' . $regex . '$#';
    }
}
