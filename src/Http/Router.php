<?php

declare(strict_types=1);

namespace Pmz\Http;

use Throwable;

final class Router
{
    /** @var array<string,array<string,callable>> Methode => Muster => Handler */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $pattern, callable $handler): self
    {
        $this->routes['GET'][$this->normalize($pattern)] = $handler;

        return $this;
    }

    public function post(string $pattern, callable $handler): self
    {
        $this->routes['POST'][$this->normalize($pattern)] = $handler;

        return $this;
    }

    public function dispatch(Request $r): Response
    {
        $method = $r->method() === 'HEAD' ? 'GET' : $r->method();
        $path   = $r->path();

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $args = $this->match($pattern, $path);
            if ($args === null) {
                continue;
            }

            try {
                return $handler($r, ...$args);
            } catch (RedirectException $e) {
                return Response::redirect($e->target);
            }
        }

        return Response::notFound();
    }

    /**
     * Gibt die Platzhalter-Werte zurueck oder null, wenn das Muster nicht passt.
     *
     * @return list<string>|null
     */
    public function match(string $pattern, string $path): ?array
    {
        $pattern = $this->normalize($pattern);
        $path    = $this->normalize($path);

        // Platzhalter greifen bewusst nicht ueber Slashes hinweg, damit
        // /invoices/{id}/pdf nicht versehentlich "7/pdf" als id einsammelt.
        $regex = '#^' . preg_replace(
            '/\\\{[A-Za-z_][A-Za-z0-9_]*\\\}/',
            '([^/]+)',
            preg_quote($pattern, '#'),
        ) . '$#';

        if (preg_match($regex, $path, $m) !== 1) {
            return null;
        }

        return array_map(rawurldecode(...), array_slice($m, 1));
    }

    private function normalize(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : '/';
    }
}
