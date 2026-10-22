<?php

require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/security.php";

use MarketMedia\Router\Router;

define("BASE", "https://www.localhost/marketmedia/router/exemple/spoofing");
$router = new Router(BASE);

/**
 * GET httpMethod
 */
$router->get(
    "/",
    function ($data) {
        $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
        echo "<h1>GET :: Spoofing</h1>", "<p>Requisição processada.</p>";
    }
);

/**
 * POST httpMethod
 */
$router->post(
    "/",
    function ($data) {
        $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
        echo "<h1>POST :: Spoofing</h1>", "<p>Requisição processada.</p>";
    },
    middleware: ExampleCsrf::class
);

/**
 * PUT spoofing and httpMethod
 */
$router->put(
    "/",
    function ($data) {
        $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
        echo "<h1>PUT :: Spoofing</h1>", "<p>Requisição processada.</p>";
    },
    middleware: ExampleCsrf::class
);

/**
 * PATCH spoofing and httpMethod
 */
$router->patch(
    "/",
    function ($data) {
        $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
        echo "<h1>PATCH :: Spoofing</h1>", "<p>Requisição processada.</p>";
    },
    middleware: ExampleCsrf::class
);

/**
 * DELETE spoofing and httpMethod
 */
$router->delete(
    "/",
    function ($data) {
        $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
        echo "<h1>DELETE :: Spoofing</h1>", "<p>Requisição processada.</p>";
    },
    middleware: ExampleCsrf::class
);

if (!$router->dispatch()) {
    http_response_code($router->error() ?? 500);
}
?>

<form action="" method="POST">
    <?= exampleCsrfField() ?>
    <select name="_method">
        <option value="POST">POST</option>
        <option value="PUT">PUT</option>
        <option value="PATCH">PATCH</option>
        <option value="DELETE">DELETE</option>
    </select>

    <input type="text" name="first_name" value="Robson"/>
    <input type="text" name="last_name" value="Leite"/>
    <input type="text" name="email" value="cursos@upinside.com.br"/>

    <button>MarketMedia</button>
</form>