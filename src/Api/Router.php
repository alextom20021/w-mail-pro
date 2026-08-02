<?php

declare(strict_types=1);

namespace MailAI\Api;

/**
 * Router
 *
 * Minimal path-pattern router — no framework dependency. Patterns use
 * `{param}` segments, e.g. "/connections/{id}". Deliberately small: this
 * API surface doesn't need Slim/Symfony Routing for ~20 endpoints, and
 * fewer dependencies means fewer things to keep patched.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * @return array{handler: callable, params: array}|null
     */
    public function match(string $method, string $path): ?array
    {
        $path = rtrim(parse_url($path, PHP_URL_PATH) ?? '/', '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            if (preg_match('#^' . $regex . '$#', $path, $m)) {
                $params = array_filter($m, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        return null;
    }
}
