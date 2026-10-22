<?php

declare(strict_types=1);

use MarketMedia\Router\Router;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Strict',
    ]);
}
if (!isset($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}

function exampleCsrfField(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . htmlspecialchars($_SESSION['_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
}

final class ExampleCsrf
{
    public function handle(Router $router): bool
    {
        $token = $router->data()['_csrf'] ?? null;
        return is_string($token) && hash_equals($_SESSION['_csrf'], $token);
    }
}
