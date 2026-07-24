<?php
namespace App;

use App\Utils\Response;

class Router {
    private array $routes = [];
    private array $globalMiddlewares = [];

    public function use(callable $middleware) {
        $this->globalMiddlewares[] = $middleware;
    }

    public function addRoute(string $method, string $path, array $middlewares, callable $handler) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'middlewares' => $middlewares,
            'handler' => $handler
        ];
    }

    public function get(string $path, array $middlewares, callable $handler) {
        $this->addRoute('GET', $path, $middlewares, $handler);
    }

    public function post(string $path, array $middlewares, callable $handler) {
        $this->addRoute('POST', $path, $middlewares, $handler);
    }

    public function put(string $path, array $middlewares, callable $handler) {
        $this->addRoute('PUT', $path, $middlewares, $handler);
    }

    public function delete(string $path, array $middlewares, callable $handler) {
        $this->addRoute('DELETE', $path, $middlewares, $handler);
    }

    public function handle(string $requestUri, string $requestMethod) {
        // Strip query string from URI
        $path = parse_url($requestUri, PHP_URL_PATH);
        $method = strtoupper($requestMethod);

        // Standardize: strip trailing slash except for root
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);
            if ($params !== null) {
                // Read request body (JSON)
                $body = [];
                $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
                if (stripos($contentType, 'application/json') !== false) {
                    $json = file_get_contents('php://input');
                    $body = json_decode($json, true) ?? [];
                } else if ($method === 'POST') {
                    $body = $_POST;
                }

                // Collect headers
                $headers = [];
                foreach ($_SERVER as $key => $val) {
                    if (strpos($key, 'HTTP_') === 0) {
                        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                        $headers[$name] = $val;
                    }
                }
                if (isset($_SERVER['CONTENT_TYPE'])) {
                    $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
                }
                if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
                } else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                }

                // Construct Request representation
                $req = [
                    'method' => $method,
                    'path' => $path,
                    'params' => $params,
                    'query' => $_GET,
                    'body' => $body,
                    'headers' => $headers,
                    'user' => null // populated by auth middleware
                ];

                // Run global middlewares, then route middlewares, then handler
                $pipeline = array_merge($this->globalMiddlewares, $route['middlewares']);
                
                $index = 0;
                $next = function() use (&$index, $pipeline, &$req, $route, &$next) {
                    if ($index < count($pipeline)) {
                        $middleware = $pipeline[$index++];
                        return $middleware($req, $this, $next);
                    } else {
                        $handler = $route['handler'];
                        return $handler($req);
                    }
                };

                try {
                    $next();
                } catch (\Throwable $e) {
                    error_log("Unhandled error in route: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    Response::error("Internal server error occurred", 500);
                }
                return;
            }
        }

        // Route not found
        Response::error("Route $method $path not found", 404);
    }

    private function matchPath(string $pattern, string $path): ?array {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        for ($i = 0; $i < count($patternParts); $i++) {
            $patternPart = $patternParts[$i];
            $pathPart = $pathParts[$i];

            if (strpos($patternPart, ':') === 0) {
                $paramName = substr($patternPart, 1);
                $params[$paramName] = urldecode($pathPart);
            } else if ($patternPart !== $pathPart) {
                return null;
            }
        }

        return $params;
    }
}
