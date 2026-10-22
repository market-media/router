<?php

require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/security.php";
require __DIR__ . "/Test/Coffee.php";
require __DIR__ . "/Test/Name.php";

/*
 * Middleware example classes
 */
require __DIR__ . "/Http/Middlewares.php";
require __DIR__ . "/Http/Guest.php";
require __DIR__ . "/Http/User.php";
require __DIR__ . "/Http/Group.php";

use MarketMedia\Router\Router;
use Http\Middlewares as Middleware;

const BASE = "https://www.localhost/marketmedia/router/exemple/controller";
$router = new Router(BASE);

/**
 * routes
 */
$router->namespace("Test");

$router->get("/", "Coffee:home");
$router->get("/edit/{id}", "Coffee:edit", middleware: Middleware::USER);
$router->post("/edit/{id}", "Coffee:edit", middleware: [Middleware::USER, \ExampleCsrf::class]);
$router->get("/logado", "Coffee:logged", middleware: \Http\User::class);
$router->get("/negado", "Coffee:denied", "coffe.denied", Middleware::USER);

/**
 * group by routes and namespace
 */
$router->group("admin", \Http\Group::class);

$router->get("/", "Coffee:admin");
$router->get("/user/{id}", "Coffee:admin");
$router->get("/user/{id}/profile", "Coffee:admin", middleware: \Http\User::class);
$router->get("/user/{id}/profile/{photo}", "Coffee:admin");

/**
 * named routes and middlewares
 */
$router->group("name");

$router->get("/", "Name:home", "name.home");
$router->get("/hello", "Name:hello", "name.hello", \Http\Guest::class);
$router->get("/params/{category}/page/{page}", "name:params", "name.params");
$router->get("/redirect", "Name:redirect", "name.redirect", Middleware::GUEST);
$router->get("/redirect/{category}/{page}", "name:redirect", "name.redirect.params");

/**
 * call route and group middleware
 */
$router->group("call", Middleware::GUEST);
$router->get(
    "/",
    function ($data, Router $route) {
        echo "<p>Requisição processada.</p>";

        echo "<a href='{$route->home()}' title='voltar'>voltar</a>";
    }
);
$router->get(
    "/{app}/",
    function ($data, Router $route) {
        echo "<p>Requisição processada.</p>";

        echo "<a href='{$route->home()}' title='voltar'>voltar</a>";
    }
);

/**
 * Group Error
 */
$router->namespace("Test")->group("error");
$router->get("/{errcode}", "Coffee:notFound");

/**
 * execute
 */
$router->dispatch();

if ($router->error()) {
    http_response_code($router->error());
    echo "<p>Não foi possível processar a requisição.</p>";
}
