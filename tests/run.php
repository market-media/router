<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MarketMedia\Router\Router;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$passed;
}

function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

class TestRouter extends Router
{
    public static string $body = '';
    public static int $reads = 0;

    protected function readBody(): string
    {
        ++self::$reads;
        return self::$body;
    }

    public function destination(string $route, ?array $data = null): string
    {
        return $this->redirectDestination($route, $data);
    }
}

function request(mixed $path = '/', string $method = 'GET', array $post = [], array $server = []): TestRouter
{
    $_GET = ['route' => $path];
    $_POST = $post;
    $_SERVER = ['REQUEST_METHOD' => $method] + $server;
    return new TestRouter('https://app.example/base');
}

class OwnerCheck
{
    public static array $seen = [];

    public function handle(Router $router): bool
    {
        self::$seen = $router->data();
        return $router->data()['id'] === '123';
    }
}

class Deny
{
    public static int $calls = 0;

    public function handle(): bool
    {
        ++self::$calls;
        return false;
    }
}

class Allow
{
    public function handle(): bool
    {
        return true;
    }
}

class TestController
{
    public static ?array $received = null;
    public static int $constructed = 0;

    public function __construct(Router $router)
    {
        ++self::$constructed;
    }

    public function show(array $data): void
    {
        self::$received = $data;
    }

    private function hidden(): void
    {
        throw new RuntimeException('Private action executed.');
    }
}

$noop = static function (): void {};
$received = null;
$router = request('/users/123/actions/456', 'POST', ['id' => '999', 'password' => 'test-secret']);
$router->post('/users/{id}/actions/{action}', static function ($data) use (&$received): void {
    $received = $data;
}, middleware: OwnerCheck::class);
$router->post('/users/{name}/{section}/{id}/archive', $noop);
check($router->dispatch(), 'Selected route must execute.');
check($received['id'] === '123' && $received['action'] === '456', 'Path parameters must override body values.');
check(OwnerCheck::$seen === $received && $router->data() === $received, 'Middleware and handler must use identical parameters.');
check(!property_exists($router->current(), 'data') && !property_exists($router->current(), 'handler'), 'Public metadata must exclude request data and handlers.');
ob_start();
var_dump($router);
$dump = ob_get_clean();
check(!str_contains($dump, 'test-secret') && !str_contains($dump, 'password'), 'Debug output must exclude request values.');

foreach ([Allow::class, []] as $middleware) {
    $executed = false;
    $router = request('/admin/resource');
    $router->group('admin', Deny::class);
    $router->get('/resource', static function () use (&$executed): void { $executed = true; }, middleware: $middleware);
    check(!$router->dispatch() && !$executed && $router->error() === 403, 'Route middleware cannot remove group protection.');
}
check(Deny::$calls === 2, 'Group protection must always run.');

$router = request('/admin/users/admin42');
$router->group('admin');
$router->get('/users/{id}', $noop);
check($router->dispatch() && $router->data()['id'] === 'admin42', 'Group names inside parameters must be preserved.');
$router = request('/0/users/42');
$router->group('0');
$router->get('/users/{id}', $noop);
check($router->dispatch() && $router->data()['id'] === '42', 'Numeric group names must be preserved.');

foreach (['/', '', '/users/42/'] as $path) {
    $router = request($path);
    $router->get($path === '/users/42/' ? '/users/{id}' : '/', $noop);
    check($router->dispatch(), 'Root and trailing slash normalization must work.');
}
foreach (['/reportXjson', "/report.json\n"] as $path) {
    $router = request($path);
    $router->get('/report.json', $noop);
    check(!$router->dispatch(), 'Literal routes must not act as regex patterns.');
}
$router = request('/report.json');
$router->get('/report.json', $noop);
check($router->dispatch(), 'Literal dots must still match themselves.');

$router = request('/admin/export');
$router->get('/admin/export', $noop, middleware: Deny::class);
$router->get('/{area}/{action}', $noop);
check(!$router->dispatch() && $router->error() === 403, 'Static protection must win over generic routes.');

foreach ([[], str_repeat('x', 4097), "/x\r\ny", '//evil.example', '/x?y', '/x\\y'] as $invalid) {
    $router = request($invalid);
    $router->get('/{id}', $noop);
    check(!$router->dispatch() && $router->error() === 400, 'Invalid path must produce a controlled 400.');
}
$router = request('/items/42', 'POST', ['_method' => 'DELETE', 'name' => '<b>raw</b>']);
$router->delete('/items/{id}', $noop);
check($router->dispatch() && $router->data() === ['name' => '<b>raw</b>', 'id' => '42'], 'POST override must preserve raw data and remove _method.');
$router = request('/items/42', 'GET', ['_method' => 'DELETE']);
$router->delete('/items/{id}', $noop);
check(!$router->dispatch() && $router->error() === 405, 'GET cannot spoof a mutation method.');
foreach ([[], 'TRACE'] as $override) {
    $router = request('/', 'POST', ['_method' => $override]);
    $router->post('/', $noop);
    check(!$router->dispatch() && $router->error() === 400, 'Invalid method overrides must be rejected.');
}

TestRouter::$body = 'name=hello+world&_method=DELETE';
TestRouter::$reads = 0;
$router = request('/items/42', 'PUT', [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8']);
$router->put('/items/{id}', $noop);
$router->put('/other/{id}', $noop);
check($router->dispatch() && $router->data()['name'] === 'hello world', 'Native PUT must parse form data without Content-Length.');
check(TestRouter::$reads === 1 && !isset($router->data()['_method']), 'Body must be read once and native method must be preserved.');
TestRouter::$body = str_repeat('x', Router::MAX_BODY_BYTES + 1);
$router = request('/', 'PATCH');
$router->patch('/', $noop);
check(!$router->dispatch() && $router->error() === 413, 'Actual body size must be bounded.');
TestRouter::$body = '{}';
$router = request('/', 'PUT', [], ['CONTENT_TYPE' => 'application/json']);
$router->put('/', $noop);
check(!$router->dispatch() && $router->error() === 415, 'Unsupported body encodings must fail explicitly.');
foreach (['-1', 'invalid', []] as $length) {
    $router = request('/', 'POST', [], ['CONTENT_LENGTH' => $length]);
    check(!$router->dispatch() && $router->error() === 400, 'Malformed Content-Length must fail safely.');
}
$router = request('/', 'POST', [], ['CONTENT_LENGTH' => Router::MAX_BODY_BYTES + 1]);
check(!$router->dispatch() && $router->error() === 413, 'Oversized declared body must be rejected.');

$router = request();
$router->get('/item/{id}', $noop, 'item');
check($router->route('item', ['id' => "a/b'?#", 'q' => 'a b']) === 'https://app.example/base/item/a%2Fb%27%3F%23?q=a%20b', 'Path parameters and query values must be encoded independently.');
foreach ([[], ['id' => []], ['id' => '..'], ['id' => '']] as $params) {
    rejects(static fn() => $router->route('item', $params), 'Invalid named parameters must be rejected.');
}
check($router->destination('item', ['id' => 42]) === 'https://app.example/base/item/42', 'Named redirects must work.');
check($router->destination('/login') === 'https://app.example/base/login', 'Internal paths must retain the base path.');
check($router->destination('https://app.example:443/login') === 'https://app.example:443/login', 'Same-origin absolute redirects must work.');
foreach (['https://evil.example', '//evil.example', 'https://app.example.evil.example', 'http://app.example', 'https://app.example:444', 'https://app.example@evil.example', 'javascript:alert(1)', "x\r\nLocation: https://evil.example", '/\\evil.example'] as $target) {
    rejects(static fn() => $router->destination($target), 'Unsafe redirects must be rejected.');
}
rejects(static fn() => new Router('javascript:alert(1)'), 'Project URL must be HTTP(S).');
rejects(static fn() => new Router('https://user:pass@app.example'), 'Project URL must exclude credentials.');
rejects(static fn() => new Router('https://app.example?x=1'), 'Project URL must exclude queries.');

$router = request('/users/42');
$router->get('/users/{id}', 'TestController:show');
check($router->dispatch() && TestController::$received === ['id' => '42'], 'Controller actions must receive route data.');
$constructed = TestController::$constructed;
$router = request('/private');
$router->get('/private', 'TestController:hidden');
check(!$router->dispatch() && $router->error() === 405 && TestController::$constructed === $constructed, 'Non-public actions must be rejected before construction.');

$router = request('/missing');
check(!$router->dispatch() && $router->error() === 404, 'Unknown paths must return 404.');
$router = request('/exists', 'POST');
$router->get('/exists', $noop);
check(!$router->dispatch() && $router->error() === 405, 'Known path with wrong verb must return 405.');

echo "Passed {$passed} regression checks on PHP " . PHP_VERSION . PHP_EOL;
