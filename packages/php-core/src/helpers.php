<?php

declare(strict_types=1);

use Paragon\Core\Application;
use Paragon\Core\Config;

if (!function_exists('app')) {
    /**
     * Resolve a service from the container, or the Application itself.
     *
     * @template T of object
     * @param class-string<T>|null $id
     * @return T|Application|object
     */
    function app(?string $id = null): object
    {
        $application = Application::instance();

        return $id === null ? $application : $application->container()->get($id);
    }
}

if (!function_exists('config')) {
    /**
     * Read a configuration value in dot notation.
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var Config $config */
        $config = Application::instance()->container()->get(Config::class);

        return $config->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Application::instance()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return Application::instance()->basePath('storage' . ($path === '' ? '' : '/' . ltrim($path, '/')));
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output.
     *
     * Named tersely because it appears in every template; the brevity is what
     * keeps it from being skipped. Raw output requires an explicit, greppable
     * alternative rather than omitting this (docs/01 §10).
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('array_get')) {
    /**
     * Read a nested array value in dot notation.
     *
     * @param array<array-key,mixed> $array
     */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('str_snake')) {
    function str_snake(string $value): string
    {
        $value = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $value) ?? $value;

        return strtolower(str_replace([' ', '-'], '_', $value));
    }
}

if (!function_exists('str_studly')) {
    function str_studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
