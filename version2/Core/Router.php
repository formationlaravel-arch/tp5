<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $handler = $this->routes[strtoupper($method)][$path] ?? null;
        if ($handler === null) {
            Response::json(['error' => 'Not found'], 404);
        }
        $handler();
    }
}
