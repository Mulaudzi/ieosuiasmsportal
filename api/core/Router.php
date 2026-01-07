<?php
/**
 * Simple Router for raw PHP API
 */

class Router {
    private $routes = [];
    private $currentMiddleware = [];
    
    public function get(string $path, $handler): void {
        $this->addRoute('GET', $path, $handler);
    }
    
    public function post(string $path, $handler): void {
        $this->addRoute('POST', $path, $handler);
    }
    
    public function put(string $path, $handler): void {
        $this->addRoute('PUT', $path, $handler);
    }
    
    public function delete(string $path, $handler): void {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    public function group(array $options, callable $callback): void {
        $previousMiddleware = $this->currentMiddleware;
        
        if (isset($options['middleware'])) {
            $this->currentMiddleware[] = $options['middleware'];
        }
        
        $callback($this);
        
        $this->currentMiddleware = $previousMiddleware;
    }
    
    private function addRoute(string $method, string $path, $handler): void {
        // Convert {param} to regex
        $pattern = preg_replace('/\{([a-zA-Z]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $this->currentMiddleware,
        ];
    }
    
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Remove /api prefix if present
        $uri = preg_replace('#^/api#', '', $uri);
        
        // Remove trailing slash
        $uri = rtrim($uri, '/') ?: '/';
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Run middleware
                foreach ($route['middleware'] as $middleware) {
                    $this->runMiddleware($middleware);
                }
                
                // Execute handler
                $this->executeHandler($route['handler'], $params);
                return;
            }
        }
        
        // 404 Not Found
        Response::error('Route not found', 404);
    }
    
    private function runMiddleware(string $middleware): void {
        switch ($middleware) {
            case 'auth':
                Auth::check();
                break;
            default:
                // Unknown middleware
                break;
        }
    }
    
    private function executeHandler($handler, array $params): void {
        if (is_callable($handler)) {
            call_user_func($handler, $params);
            return;
        }
        
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($controller, $method) = explode('@', $handler);
            
            $controllerFile = __DIR__ . '/../controllers/' . $controller . '.php';
            if (!file_exists($controllerFile)) {
                Response::error("Controller $controller not found", 500);
            }
            
            require_once $controllerFile;
            
            if (!class_exists($controller)) {
                Response::error("Controller class $controller not found", 500);
            }
            
            $instance = new $controller();
            
            if (!method_exists($instance, $method)) {
                Response::error("Method $method not found in $controller", 500);
            }
            
            $instance->$method($params);
            return;
        }
        
        Response::error('Invalid route handler', 500);
    }
}
