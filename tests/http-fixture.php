<?php

declare(strict_types=1);

if (str_starts_with($_SERVER['REQUEST_URI'], '/exemple/')) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use MarketMedia\Router\Router;

$router = new Router('http://127.0.0.1:' . $_SERVER['SERVER_PORT']);
$respond = static function (array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
};
$router->get('/health', $respond);
$router->put('/items/{id}', $respond);
$router->delete('/items/{id}', $respond);
$router->get('/redirect', static function (array $data, Router $router): void {
    $router->redirect($_GET['to'] ?? '/health');
});
try {
    if (!$router->dispatch()) {
        http_response_code($router->error() ?? 500);
    }
} catch (InvalidArgumentException) {
    http_response_code(400);
}
