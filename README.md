# Router @market-media

Pequeno, simples e descomplicado. O router é um componentes de rotas PHP com abstração para MVC. Preparado com verbos
RESTfull (GET, POST, PUT, PATCH e DELETE), trabalha em sua própria camada de forma isolada e pode ser integrado sem
segredos a sua aplicação.

### Destaques

- Classe roteador com todos os verbos RESTful (Classe roteador com todos os verbos RESTful)
- Despacho otimizado com controle total de decisões
- Solicitando Spoofing para Verbalização Local (Falsificador (Spoofing) de requisição para verbalização local)
- É muito simples criar rotas para sua aplicação ou API (É muito simples criar rotas para sua aplicação ou API)
- Gatilho e suporte de dados para o controlador (Gatilho e transportador de dados para o controleador)
- Composer ready e compatível com PSR-2 (Pronto para o compositor e compatível com PSR-2)

## Instalação

Router está disponível via Composer:

```bash
"market-media/router": "2.0.*"
```

or run

```bash
composer require market-media/router
```

## Documentação

Para mais detalhes sobre como usar o router, veja a pasta de exemplo com detalhes no diretório do componente. Para usar
o router é preciso redirecionar sua navegação para o arquivo raiz de rotas (index.php) onde todo o tráfego deve ser
tratado. O exemplo abaixo mostra como:

#### Apache

```apacheconfig
RewriteEngine On
#Options All -Indexes

## ROUTER WWW Redirect.
#RewriteCond %{HTTP_HOST} !^www\. [NC]
#RewriteRule ^ https://www.%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

## ROUTER HTTPS Redirect
#RewriteCond %{HTTP:X-Forwarded-Proto} !https
#RewriteCond %{HTTPS} off
#RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# ROUTER URL Rewrite
RewriteCond %{SCRIPT_FILENAME} !-f
RewriteCond %{SCRIPT_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=/$1 [L,QSA]
```

#### Nginx

````nginxconfig
location / {
  if ($script_filename !~ "-f"){
    rewrite ^(.*)$ /index.php?route=/$1 break;
  }
}
````

##### Rotas

```php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

/**
 * routes
 * The controller must be in the namespace Test\Controller
 * this produces routes for route, route/$id, route/{$id}/profile, etc.
 */
$router->namespace("Test");

$router->get("/route", "Controller:method");
$router->post("/route/{id}", "Controller:method");
$router->put("/route/{id}/profile", "Controller:method");
$router->patch("/route/{id}/profile/{photo}", "Controller:method");
$router->delete("/route/{id}", "Controller:method");

/**
 * group by routes and namespace
 * this produces routes for /admin/route and /admin/route/$id
 * The controller must be in the namespace Dash\Controller
 */
$router->group("admin")->namespace("Dash");

$router->get("/route", "Controller:method");
$router->post("/route/{id}", "Controller:method");

/**
 * sub group
 */
$router->group("admin/support");

$router->get("/tickets", "Controller:method");
$router->post("/ticket/{id}", "Controller:method");

/**
 * Group Error
 * This monitors all Router errors. Are they: 400 Bad Request, 404 Not Found, 405 Method Not Allowed and 501 Not Implemented
 */
$router->group("error")->namespace("Test");
$router->get("/{errcode}", "Coffee:notFound");

/**
 * This method executes the routes
 */
$router->dispatch();

/*
 * Redirect all errors
 */
if ($router->error()) {
    $router->redirect("/error/{$router->error()}");
}
```

##### Named

```php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

/**
 * routes
 * The controller must be in the namespace Test\Controller
 */
$router->namespace("Test")->group("name");

$router->get("/", "Name:home", "name.home");
$router->get("/hello", "Name:hello", "name.hello");
$router->get("/redirect", "Name:redirect", "name.redirect");

/**
 * This method executes the routes
 */
$router->dispatch();

/*
 * Redirect all errors
 */
if ($router->error()) {
    $router->redirect("name.hello");
}
```

###### Named Controller Example

```php
<?php

class Name
{
    public function __construct($router)
    {
        $this->router = $router;
    }

    public function home(): void
    {
        echo "<h1>Home</h1>";
        echo "<p>", $this->router->route("name.home"), "</p>";
        echo "<p>", $this->router->route("name.hello"), "</p>";
        echo "<p>", $this->router->route("name.redirect"), "</p>";
    }

    public function redirect(): void
    {
        $this->router->redirect("name.hello");
    }
}
```

###### Named Params

````php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

$this->router->route("name.params", [
    "category" => 22,
    "page" => 2
]);

//result
//https://www.youdomain.com/name/params/22/page/2

$this->router->route("name.params", [
    "category" => 22,
    "page" => 2,
    "argument1" => "most filter",
    "argument2" => "most search"
]);

//result
//https://www.youdomain.com/name/params/22/page/2?argument1=most+filter&argument2=most+search
````

##### Callable

```php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

/**
 * GET httpMethod
 */
$router->get("/", function ($data) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>GET :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
});

/**
 * GET httpMethod and Route
 */
 $router->get("/", function ($data, Router $route) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>GET :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
    var_dump($route->current());
});

/**
 * POST httpMethod
 */
$router->post("/", function ($data) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>POST :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
});

/**
 * PUT spoofing and httpMethod
 */
$router->put("/", function ($data) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>PUT :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
});

/**
 * PATCH spoofing and httpMethod
 */
$router->patch("/", function ($data) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>PATCH :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
});

/**
 * DELETE spoofing and httpMethod
 */
$router->delete("/", function ($data) {
    $data = ["realHttp" => $_SERVER["REQUEST_METHOD"]] + $data;
    echo "<h1>DELETE :: Spoofing</h1>", "<pre>", print_r($data, true), "</pre>";
});

$router->dispatch();
```

##### Simple Middleware

```php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

//simple
$router->get("/edit/{id}", "Coffee:edit", middleware: \Http\Guest::class);
$router->get("/denied", "Coffee:denied", "coffe.denied", \Http\Group::class);

//multiple
$router->get("/logado", "Coffee:logged", middleware: [\Http\Guest::class, \Http\Group::class]);

//callable
$router->get("/call", function ($data, Router $router){
    //code here
}, middleware: \Http\Guest::class);
```

##### Simple Middleware Group

```php
<?php

use MarketMedia\Router\Router;

$router = new Router("https://www.youdomain.com");

//group single or multiple
$router->group("name", \Http\Guest::class);
$router->get("/", "Name:home", "name.home");
$router->get("/hello", "Name:hello", "name.hello");
$router->get("/redirect", "Name:redirect", "name.redirect");
```

##### Simple Middleware Class Example

```php
<?php

namespace Http;

use MarketMedia\Router\Router;

class User
{
    public function handle(Router $router): bool
    {
        $user = true;
        if ($user) {
            var_dump($router->current());
            return true;
        }
        return false;
    }
}
```

##### Form Spoofing

Esse exemplo mostra como acessar as rotas (PUT, PATCH, DELETE) a partir da aplicação. Você pode ver mais detalhes na
pasta de exemplo. De uma atenção para o campo _method, ele pode ser do tipo hidden.

```html

<form action="" method="POST">
    <select name="_method">
        <option value="POST">POST</option>
        <option value="PUT">PUT</option>
        <option value="PATCH">PATCH</option>
        <option value="DELETE">DELETE</option>
    </select>

    <input type="text" name="first_name" value="Market"/>
    <input type="text" name="last_name" value="Media"/>
    <input type="text" name="email" value="jose@marketmedia.com.br"/>

    <button>MarketMedia</button>
</form>
```

##### PHP cURL example

```php
<?php

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "http://localhost/marketmedia/router/example/spoofing/",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "PUT",
  CURLOPT_POSTFIELDS => "first_name=Robson&last_name=Leite&email=cursos%40upinside.com.br",
  CURLOPT_HTTPHEADER => array(
    "Cache-Control: no-cache",
    "Content-Type: application/x-www-form-urlencoded"
  ),
));

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
  echo $response;
}
```
## License

The MIT License (MIT). Please see [License File](https://github.com/robsonvleite/datalayer/blob/master/LICENSE) for more
information.