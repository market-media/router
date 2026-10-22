<?php

declare(strict_types=1);

namespace MarketMedia\Router;

abstract class Dispatch
{
    use RouterTrait;

    protected string $projectUrl;
    protected string $httpMethod;
    protected string $path = '/';
    protected ?array $route = null;
    protected array $routes = [];
    protected string $separator;
    protected ?string $namespace = null;
    protected ?string $group = null;
    protected ?array $middleware = null;
    protected ?array $data = null;
    protected array $bodyData = [];
    protected ?int $error = null;
    protected ?int $requestError = null;

    public const int BAD_REQUEST = 400;
    public const int FORBIDDEN = 403;
    public const int NOT_FOUND = 404;
    public const int METHOD_NOT_ALLOWED = 405;
    public const int PAYLOAD_TOO_LARGE = 413;
    public const int UNSUPPORTED_MEDIA_TYPE = 415;
    public const int NOT_IMPLEMENTED = 501;
    public const int MAX_PATH_BYTES = 4096;
    public const int MAX_BODY_BYTES = 1048576;

    public function __construct(string $projectUrl, ?string $separator = ':')
    {
        $this->assertHttpUrl($projectUrl);
        $parts = parse_url($projectUrl);
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Project URL cannot contain a query or fragment.');
        }
        $this->projectUrl = rtrim($projectUrl, '/');
        $this->separator = $separator ?? ':';
        if ($this->separator === '') {
            throw new \InvalidArgumentException('Handler separator cannot be empty.');
        }
        $path = $_GET['route'] ?? '/';
        if (!is_string($path) || strlen($path) > self::MAX_PATH_BYTES
            || preg_match('/[\x00-\x20\x7f\\\\?#]/', $path)
            || ($path !== '' && !str_starts_with($path, '/')) || str_starts_with($path, '//')) {
            $this->requestError = self::BAD_REQUEST;
        } else {
            $this->path = rtrim($path, '/') ?: '/';
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($method) || !preg_match('/\A[A-Z]+\z/', $method)) {
            $this->requestError = self::BAD_REQUEST;
            $method = 'GET';
        }
        $this->httpMethod = $method;
        if ($this->requestError === null) {
            $this->formSpoofing();
        }
    }

    /** Exclude request data, handlers and middleware internals from dumps. */
    public function __debugInfo(): array
    {
        return ['routeCount' => array_sum(array_map('count', $this->routes)), 'error' => $this->error];
    }

    public function route(string $name, ?array $data = null): ?string
    {
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if ($route['name'] === $name) {
                    return $this->treat($route, $data);
                }
            }
        }
        return null;
    }

    public function namespace(?string $namespace): Dispatch
    {
        $this->namespace = $namespace !== null ? trim($namespace, '\\') : null;
        return $this;
    }

    public function group(?string $group, array|string|null $middleware = null): Dispatch
    {
        $normalized = $group !== null ? trim($group, '/') : '';
        $this->group = $normalized !== '' ? $normalized : null;
        $this->middleware = [$this->group ?? '' => (array) $middleware];
        return $this;
    }

    public function data(): ?array
    {
        return $this->data;
    }

    /** Public metadata only; request values are available explicitly via data(). */
    public function current(): ?object
    {
        if ($this->route === null) {
            return null;
        }
        return (object) array_intersect_key($this->route, array_flip(['route', 'name', 'method', 'namespace', 'group']));
    }

    public function home(): string
    {
        return $this->projectUrl;
    }

    /** Redirects are restricted to the configured origin. */
    public function redirect(string $route, ?array $data = null): void
    {
        $destination = $this->redirectDestination($route, $data);
        header('Location: ' . $destination, true, 302);
        exit;
    }

    protected function redirectDestination(string $route, ?array $data = null): string
    {
        $destination = $this->route($route, $data);
        if ($destination === null) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $route) || str_starts_with($route, '//')) {
                throw new \InvalidArgumentException('Invalid redirect destination.');
            }
            $destination = preg_match('/\A[a-z][a-z0-9+.-]*:/i', $route)
                ? $route : $this->projectUrl . '/' . ltrim($route, '/');
        }
        $this->assertHttpUrl($destination);
        $base = parse_url($this->projectUrl);
        $target = parse_url($destination);
        $port = static fn(array $url): int => $url['port'] ?? (strtolower($url['scheme']) === 'https' ? 443 : 80);
        if (strtolower($base['scheme']) !== strtolower($target['scheme'])
            || strtolower($base['host']) !== strtolower($target['host']) || $port($base) !== $port($target)) {
            throw new \InvalidArgumentException('External redirects are not allowed.');
        }
        return $destination;
    }

    private function assertHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Expected an absolute HTTP(S) URL without credentials.');
        }
    }

    public function error(): ?int
    {
        return $this->error;
    }

    public function dispatch(): bool
    {
        $this->error = $this->requestError;
        $this->route = null;
        $this->data = null;
        if ($this->error !== null) {
            return false;
        }
        $routes = array_values($this->routes[$this->httpMethod] ?? []);
        // Static routes win; equal specificity preserves registration order.
        usort($routes, static fn(array $a, array $b): int => $b['specificity'] <=> $a['specificity']);
        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $this->path, $found) === 1) {
                array_shift($found);
                $params = array_combine($route['keys'], $found);
                $this->data = array_replace($this->bodyData, $params);
                $this->route = $route;
                return $this->execute();
            }
        }
        $this->error = self::NOT_FOUND;
        foreach ($this->routes as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                if (preg_match($route['pattern'], $this->path) === 1) {
                    $this->error = self::METHOD_NOT_ALLOWED;
                    return false;
                }
            }
        }
        return false;
    }
}
