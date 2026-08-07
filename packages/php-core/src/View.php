<?php

declare(strict_types=1);

namespace Paragon\Core;

use RuntimeException;
use Throwable;

/**
 * Plain-PHP template renderer.
 *
 * No template compiler and no build step: templates are PHP, rendered in an
 * isolated scope with output buffering. On shared hosting that is faster than
 * a compiling engine and removes a whole class of cache-invalidation problems.
 *
 * Values are NOT auto-escaped, because a compiler-free renderer cannot know
 * intent. Templates call e() — short deliberately, so it is never skipped for
 * brevity's sake (docs/01 §10).
 */
final class View
{
    /** @var array<string,mixed> Data shared with every view. */
    private array $shared = [];

    /** @var array<string,string> Captured named sections. */
    private array $sections = [];

    /** @var list<string> Open section stack. */
    private array $sectionStack = [];

    public function __construct(private readonly string $path)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Render a template inside a layout.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        $content = $this->capture($template, $data);

        if ($layout === null) {
            return new Response($content);
        }

        return new Response($this->capture($layout, [...$data, 'content' => $content]));
    }

    /**
     * Render a template and return the markup, without a layout.
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->path . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View [{$template}] not found at {$file}.");
        }

        $level = ob_get_level();
        ob_start();

        try {
            (function () use ($file, $data): void {
                // extract() into a closure scope so templates cannot reach
                // $this or the renderer's own locals.
                extract([...$this->shared, ...$data], EXTR_SKIP);

                require $file;
            })();
        } catch (Throwable $e) {
            // Discard partial output so a broken template cannot emit half a
            // page followed by an error.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->sectionStack === []) {
            throw new RuntimeException('endSection() called with no open section.');
        }

        $name = array_pop($this->sectionStack);
        $this->sections[$name] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }
}
