<?php

namespace App\Service;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

class OpenApiSpecBuilder
{
    private const SUPPORTED_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function build(): array
    {
        $paths = [];

        foreach ($this->router->getRouteCollection()->all() as $routeName => $route) {
            $path = $route->getPath();
            if (!$this->isDocumentedApiPath($path)) {
                continue;
            }

            $controller = $this->resolveController($route);
            [$summary, $description] = $this->extractControllerDocumentation($controller);
            $tag = $this->inferTag($controller, $path);
            $parameters = $this->buildPathParameters($route, $controller);
            $methods = $route->getMethods() ?: ['GET'];

            foreach ($methods as $method) {
                $httpMethod = strtolower($method);
                if (!in_array($httpMethod, self::SUPPORTED_METHODS, true)) {
                    continue;
                }

                $operation = [
                    'tags' => [$tag],
                    'operationId' => $this->buildOperationId($routeName, $httpMethod),
                    'summary' => $summary ?? sprintf('%s %s', strtoupper($httpMethod), $path),
                    'responses' => $this->defaultResponses(),
                ];

                if ($description !== null) {
                    $operation['description'] = $description;
                }

                if (!empty($parameters)) {
                    $operation['parameters'] = $parameters;
                }

                if (in_array($httpMethod, ['post', 'put', 'patch'], true)) {
                    $operation['requestBody'] = $this->defaultRequestBody();
                }

                $paths[$path][$httpMethod] = $operation;
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'ARCHE GUI Backend API',
                'version' => '1.0.0',
                'description' => 'Auto-generated OpenAPI specification based on registered Symfony routes.',
            ],
            'servers' => $this->buildServers(),
            'paths' => $paths,
        ];
    }

    private function buildServers(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [
                ['url' => '/'],
            ];
        }

        return [
            ['url' => $request->getSchemeAndHttpHost()],
        ];
    }

    private function isDocumentedApiPath(string $path): bool
    {
        if (!str_starts_with($path, '/api/')) {
            return false;
        }

        return !str_starts_with($path, '/api/docs');
    }

    private function resolveController(Route $route): ?string
    {
        $controller = $route->getDefault('_controller') ?? $route->getDefault('controller');
        return is_string($controller) ? ltrim($controller, '\\') : null;
    }

    private function buildOperationId(string $routeName, string $method): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', strtolower(sprintf('%s_%s', $method, $routeName))) ?? uniqid('op_', true);
    }

    private function inferTag(?string $controller, string $path): string
    {
        if ($controller !== null && str_contains($controller, '::')) {
            [$className] = explode('::', $controller, 2);
            try {
                $reflection = new ReflectionClass($className);
                return str_replace('Controller', '', $reflection->getShortName());
            } catch (ReflectionException) {
            }
        }

        $parts = explode('/', trim($path, '/'));
        return isset($parts[1]) ? ucfirst($parts[1]) : 'Api';
    }

    private function buildPathParameters(Route $route, ?string $controller): array
    {
        $path = $route->getPath();
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $parameterNames = $matches[1] ?? [];
        if (empty($parameterNames)) {
            return [];
        }

        $controllerParamTypes = $this->extractControllerParameterTypes($controller);
        $requirements = $route->getRequirements();
        $defaults = $route->getDefaults();
        $parameters = [];

        foreach ($parameterNames as $parameterName) {
            $schema = ['type' => 'string'];
            $requirement = $requirements[$parameterName] ?? null;
            $default = $defaults[$parameterName] ?? null;

            if (isset($controllerParamTypes[$parameterName])) {
                $schema['type'] = $controllerParamTypes[$parameterName];
            } elseif (is_string($requirement) && preg_match('/^\^?\\d+\$?$/', $requirement)) {
                $schema['type'] = 'integer';
            } elseif (is_int($default)) {
                $schema['type'] = 'integer';
            }

            if (is_string($requirement) && $requirement !== '') {
                $schema['pattern'] = $requirement;
            }

            if ($default !== null) {
                $schema['example'] = $default;
            } elseif ($parameterName === 'lang') {
                $schema['example'] = 'en';
            }

            $parameters[] = [
                'name' => $parameterName,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    private function extractControllerParameterTypes(?string $controller): array
    {
        if ($controller === null || !str_contains($controller, '::')) {
            return [];
        }

        [$className, $methodName] = explode('::', $controller, 2);
        if (!class_exists($className) || !method_exists($className, $methodName)) {
            return [];
        }

        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);
        } catch (ReflectionException) {
            return [];
        }

        $parameterTypes = [];
        foreach ($reflectionMethod->getParameters() as $parameter) {
            $reflectionType = $parameter->getType();
            if ($reflectionType === null || !$reflectionType->isBuiltin()) {
                continue;
            }

            $openApiType = match ($reflectionType->getName()) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'array' => 'array',
                default => 'string',
            };

            $parameterTypes[$parameter->getName()] = $openApiType;
        }

        return $parameterTypes;
    }

    private function extractControllerDocumentation(?string $controller): array
    {
        if ($controller === null || !str_contains($controller, '::')) {
            return [null, null];
        }

        [$className, $methodName] = explode('::', $controller, 2);
        if (!class_exists($className) || !method_exists($className, $methodName)) {
            return [null, null];
        }

        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);
        } catch (ReflectionException) {
            return [null, null];
        }

        $docComment = $reflectionMethod->getDocComment();
        if (!is_string($docComment) || $docComment === '') {
            return [null, null];
        }

        $lines = explode("\n", $docComment);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*?/', '', $line) ?? $line;
            $line = preg_replace('/\*\/$/', '', $line) ?? $line;
            $line = preg_replace('/^\*\s?/', '', $line) ?? $line;
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $cleanLines[] = $line;
        }

        if (empty($cleanLines)) {
            return [null, null];
        }

        $summary = array_shift($cleanLines);
        $description = !empty($cleanLines) ? implode(' ', $cleanLines) : null;

        return [$summary, $description];
    }

    private function defaultResponses(): array
    {
        return [
            '200' => [
                'description' => 'Successful response',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
            'default' => [
                'description' => 'Error response',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function defaultRequestBody(): array
    {
        return [
            'required' => false,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
        ];
    }
}
