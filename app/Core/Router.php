<?php

declare(strict_types=1);

namespace GoldBot\Core;

use Closure;
use GoldBot\Http\Middleware\MiddlewareInterface;
use RuntimeException;

/**
 * Route table and dispatcher.
 *
 * Middleware runs as an onion: each layer may short-circuit by returning a
 * response instead of calling the next. That is how Authenticate redirects to
 * the login page without the controller ever running.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<class-string> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    public function get(string $pattern, string $controller, string $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $pattern, $controller, $action, $middleware, $name);
    }

    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    public function post(string $pattern, string $controller, string $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $pattern, $controller, $action, $middleware, $name);
    }

    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    public function put(string $pattern, string $controller, string $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $pattern, $controller, $action, $middleware, $name);
    }

    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    public function delete(string $pattern, string $controller, string $action, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $pattern, $controller, $action, $middleware, $name);
    }

    /**
     * Register routes sharing a prefix and middleware stack.
     *
     * @param list<class-string> $middleware
     */
    public function group(string $prefix, array $middleware, Closure $routes): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = rtrim($previousPrefix . $prefix, '/');
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        $routes($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    private function add(string $method, string $pattern, string $controller, string $action, array $middleware, ?string $name): void
    {
        $full = $this->groupPrefix . $pattern;
        $full = '/' . trim($full, '/');

        $this->routes[] = new Route(
            $method,
            $full === '/' ? '/' : rtrim($full, '/'),
            $controller,
            $action,
            [...$this->groupMiddleware, ...$middleware],
            $name
        );
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route->matches($request->method(), $path)) {
                return $this->runThroughMiddleware($route, $request);
            }
        }

        // Distinguish "no such path" from "wrong verb for this path" — a 405
        // with an Allow header is far more useful when debugging a form post.
        $allowed = $this->methodsFor($path);

        if ($allowed !== []) {
            throw new HttpException(405, 'That method is not allowed here.');
        }

        throw HttpException::notFound();
    }

    /** @return list<string> */
    private function methodsFor(string $path): array
    {
        $methods = [];

        foreach ($this->routes as $route) {
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                if ($route->method === $method && $route->matches($method, $path)) {
                    $methods[] = $method;
                }
            }
        }

        return array_values(array_unique($methods));
    }

    private function runThroughMiddleware(Route $route, Request $request): Response
    {
        $parameters = $route->parameters();

        $destination = function (Request $request) use ($route, $parameters): Response {
            $controller = $this->container->get($route->controller);

            if (!method_exists($controller, $route->action)) {
                throw new RuntimeException(
                    sprintf('Action [%s::%s] does not exist.', $route->controller, $route->action)
                );
            }

            /** @var Response $response */
            $response = $controller->{$route->action}($request, ...array_values($parameters));

            return $response;
        };

        // Compose in reverse so the first-listed middleware is outermost.
        $pipeline = array_reduce(
            array_reverse($route->middleware),
            function (Closure $next, string $middlewareClass): Closure {
                return function (Request $request) use ($next, $middlewareClass): Response {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = $this->container->get($middlewareClass);

                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    public function urlFor(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                return $route->url($parameters);
            }
        }

        throw new RuntimeException("No route named [{$name}].");
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
