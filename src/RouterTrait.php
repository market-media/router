<?php

declare(strict_types=1);

namespace MarketMedia\Router;

trait RouterTrait
{
    protected function addRoute(
        string $method,
        string $route,
        callable|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $route = '/' . trim(($this->group !== null ? $this->group . '/' : '') . ltrim($route, '/'), '/');
        $keys = [];
        $pattern = '';
        $offset = 0;
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_-]*)\}/', $route, $matches, PREG_OFFSET_CAPTURE);
        $literalLength = 0;
        foreach ($matches[0] as $index => [$token, $position]) {
            $key = $matches[1][$index][0];
            if (in_array($key, $keys, true)) {
                throw new \InvalidArgumentException('Duplicate route parameter.');
            }
            $literal = substr($route, $offset, $position - $offset);
            $pattern .= preg_quote($literal, '~') . '([^/]+)';
            $literalLength += strlen($literal);
            $keys[] = $key;
            $offset = $position + strlen($token);
        }
        $literal = substr($route, $offset);
        $pattern .= preg_quote($literal, '~');
        $literalLength += strlen($literal);
        $groupMiddleware = $this->middleware[$this->group ?? ''] ?? [];
        $middlewares = array_merge((array) $groupMiddleware, (array) $middleware);
        [$resolvedHandler, $action] = $this->resolveHandler($handler);

        $this->routes[$method][$route] = [
            'route' => $route,
            'name' => $name,
            'method' => $method,
            'middlewares' => $middlewares,
            'handler' => $resolvedHandler,
            'action' => $action,
            'namespace' => $this->namespace,
            'group' => $this->group,
            'pattern' => '~\A' . $pattern . '\z~D',
            'keys' => $keys,
            'specificity' => [count($keys) === 0 ? 1 : 0, $literalLength, -count($keys)],
        ];
    }

    /** Read the request once; method overrides are accepted only on real POSTs. */
    protected function formSpoofing(): void
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($length !== null && (!is_scalar($length) || !ctype_digit((string) $length))) {
            $this->requestError = self::BAD_REQUEST;
            return;
        }
        if ($length !== null && (float) $length > self::MAX_BODY_BYTES) {
            $this->requestError = self::PAYLOAD_TOO_LARGE;
            return;
        }
        if ($this->httpMethod === 'POST') {
            $this->bodyData = $_POST;
            $override = $this->bodyData['_method'] ?? null;
            if ($override !== null) {
                if (!is_string($override) || !in_array($override, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $this->requestError = self::BAD_REQUEST;
                    return;
                }
                $this->httpMethod = $override;
            }
        } elseif (in_array($this->httpMethod, ['PUT', 'PATCH', 'DELETE'], true)) {
            $body = $this->readBody();
            if (strlen($body) > self::MAX_BODY_BYTES) {
                $this->requestError = self::PAYLOAD_TOO_LARGE;
                return;
            }
            $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
            if ($body !== '' && $contentType !== 'application/x-www-form-urlencoded') {
                $this->requestError = self::UNSUPPORTED_MEDIA_TYPE;
                return;
            }
            parse_str($body, $this->bodyData);
        }
        unset($this->bodyData['_method']);
    }

    protected function readBody(): string
    {
        $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($body === false) {
            $this->requestError = self::BAD_REQUEST;
            return '';
        }
        return $body;
    }

    private function execute(): bool
    {
        if (!$this->route) {
            $this->error = self::NOT_FOUND;
            return false;
        }
        foreach ($this->route['middlewares'] as $middleware) {
            if (!is_string($middleware) || !class_exists($middleware)) {
                $this->error = self::NOT_IMPLEMENTED;
                return false;
            }
            $instance = new $middleware();
            if (!is_callable([$instance, 'handle'])) {
                $this->error = self::METHOD_NOT_ALLOWED;
                return false;
            }
            if ($instance->handle($this) !== true) {
                $this->error = self::FORBIDDEN;
                return false;
            }
        }
        if ($this->route['action'] === null && is_callable($this->route['handler'])) {
            ($this->route['handler'])($this->data, $this);
            return true;
        }
        $controller = $this->route['handler'];
        if (!is_string($controller) || !class_exists($controller)) {
            $this->error = self::BAD_REQUEST;
            return false;
        }
        $method = $this->route['action'];
        if (!is_string($method) || !method_exists($controller, $method)) {
            $this->error = self::METHOD_NOT_ALLOWED;
            return false;
        }
        $reflection = new \ReflectionMethod($controller, $method);
        if (!$reflection->isPublic() || str_starts_with($method, '__')) {
            $this->error = self::METHOD_NOT_ALLOWED;
            return false;
        }
        $instance = new $controller($this);
        $instance->$method($this->data);
        return true;
    }

    private function resolveHandler(callable|string $handler): array
    {
        if (!is_string($handler) || is_callable($handler)) {
            return [$handler, null];
        }
        $parts = explode($this->separator, $handler);
        if (count($parts) !== 2 || $parts[0] === '' || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $parts[1])) {
            throw new \InvalidArgumentException('Expected Controller:method or a callable.');
        }
        $controller = str_starts_with($parts[0], '\\')
            ? $parts[0]
            : ($this->namespace ? $this->namespace . '\\' : '') . $parts[0];
        return [$controller, $parts[1]];
    }

    private function treat(array $route, ?array $data = null): string
    {
        $data ??= [];
        $arguments = [];
        foreach ($route['keys'] as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                throw new \InvalidArgumentException('Missing or invalid route parameter: ' . $key);
            }
            $value = (string) $data[$key];
            if ($value === '' || $value === '.' || $value === '..') {
                throw new \InvalidArgumentException('Invalid route segment.');
            }
            $arguments['{' . $key . '}'] = rawurlencode($value);
            unset($data[$key]);
        }
        $query = $data ? '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986) : '';
        return $this->projectUrl . strtr($route['route'] === '/' ? '' : $route['route'], $arguments) . $query;
    }
}
