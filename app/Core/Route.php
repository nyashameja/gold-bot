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

    /**
     * Fill a pattern back in, for link generation.
     *
     * The constraint matcher allows one level of nested braces for the same
     * reason compile() does: `{uuid:[0-9a-f-]{36}}` otherwise loses only its
     * final brace, producing a URL like `/signals/abc}/cancel` — a link that
     * looks nearly right and 404s.
     *
     * @param array<string,string|int> $parameters
     */
    public function url(array $parameters = []): string
    {
        $url = $this->pattern;

        foreach ($parameters as $key => $value) {
            $pattern = '/\{' . preg_quote((string) $key, '/') . '(?::(?:[^{}]|\{[^{}]*\})+)?\}/';
            $url = preg_replace($pattern, (string) $value, $url) ?? $url;
        }

        return $url;
    }

    /**
     * Compile a pattern into a matching regex.
     *
     * The literal segments are quoted INDIVIDUALLY and the placeholders are
     * emitted untouched. Quoting the whole pattern first and substituting
     * afterwards does not work — preg_quote escapes the placeholder's own
     * braces, so `{uuid}` becomes `\{uuid\}`, the substitution no longer
     * matches it, and the route silently matches nothing. Silently, because a
     * route that never matches produces a 404 rather than an error, which
     * looks exactly like a missing record.
     */
    private function compile(string $pattern): string
    {
        $this->parameterNames = [];

        // The constraint may itself contain braces — `{uuid:[0-9a-f-]{36}}` is
        // the obvious case. A naive `[^}]+` stops at the first closing brace
        // and truncates the quantifier, so one level of nesting is matched
        // explicitly. That covers every quantifier form; genuinely recursive
        // constraints are not a thing anyone should be writing in a route.
        $placeholder = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/';

        $segments = preg_split(
            $placeholder,
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        preg_match_all($placeholder, $pattern, $matches, PREG_SET_ORDER);

        $regex = '';
        $remaining = $pattern;

        foreach ($matches as $match) {
            $position = strpos($remaining, $match[0]);

            if ($position === false) {
                continue;
            }

            // Everything before this placeholder is a literal: quoted, so a
            // dot in a path cannot act as a wildcard.
            $regex .= preg_quote(substr($remaining, 0, $position), '#');

            $name = $match[1];
            $constraint = ($match[2] ?? '') !== '' ? $match[2] : '[^/]+';

            $this->parameterNames[] = $name;
            $regex .= '(?P<' . $name . '>' . $constraint . ')';

            $remaining = substr($remaining, $position + strlen($match[0]));
        }

        $regex .= preg_quote($remaining, '#');

        return '#^' . $regex . '$#';
    }
}
